<?php

/*
|--------------------------------------------------------------------------
| ETA E-Invoicing — submission JSON, status lifecycle, RBAC gate, scoping
|--------------------------------------------------------------------------
| Egyptian Tax Authority B2B e-invoice submission. These scenarios cover the
| three load-bearing surfaces around app/Services/Eta/*:
|
|   1. JSON SHAPE   — totals/tax/tenant tax_id propagate correctly, multiple
|                     invoice lines aggregate, business-without-tax_id is
|                     rejected up front. (Net-new vs EtaJsonBuilderTest, which
|                     only asserts a single happy line + an individual tenant.)
|   2. LIFECYCLE    — unsubmitted -> submitted/valid via EtaSubmissionService;
|                     a Valid invoice is idempotent on re-submit; a rejected
|                     submission persists eta_status=rejected + carries the
|                     error in eta_response.
|   3. GATE         — invoices.submit_to_eta is held by accounting + super_admin
|                     only; manager / viewer / leasing / operations / marketing
|                     / hr / owner do NOT hold it.
|   4. SCOPING      — submitting one invoice does not bleed ETA state onto
|                     another property's invoice.
|
| No real HTTP: the mock client returns a deterministic Valid response; the
| rejected/exception paths bind a fake EtaApiClient into the container.
*/

use App\Models\Charge;
use App\Models\InvoiceItem;
use App\Services\Eta\EtaApiClient;
use App\Services\Eta\EtaJsonBuilder;
use App\Services\Eta\EtaSubmissionService;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Persist an invoice line. InvoiceItem::saving recomputes vat_amount + total
 * from amount + vat_rate, so callers only supply amount + rate.
 */
function etaLine($invoice, ?Charge $charge, string $description, string $type, float $amount, float $vatRate): InvoiceItem
{
    return InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'charge_id' => $charge?->id,
        'description' => $description,
        'type' => $type,
        'amount' => $amount,
        'vat_rate' => $vatRate,
    ]);
}

/** A fully-wired business invoice (asset -> unit -> lease -> tenant -> invoice). */
function etaBusinessInvoice(array $tenantAttrs = [], array $invoiceAttrs = [])
{
    $asset = makeAsset(['code' => 'ETA'.strtoupper(substr(uniqid(), -3))]);
    $unit = makeUnit($asset, ['status' => 'occupied']);
    $tenant = makeTenant(array_merge([
        'name' => 'Acme Co',
        'legal_name' => 'Acme Trading LLC',
        'tax_id' => '111-222-333',
        'type' => 'company',
        'address' => '5 Tahrir St',
    ], $tenantAttrs));
    $lease = makeLease($unit, $tenant);

    return [$asset, $unit, $tenant, $lease, makeInvoice($lease, $invoiceAttrs)];
}

/** A fake ETA client returning whatever array it's constructed with (or throwing). */
function bindEtaClient(array $response = [], ?Throwable $throws = null): void
{
    app()->bind(EtaApiClient::class, fn () => new class($response, $throws) extends EtaApiClient
    {
        public function __construct(private array $resp, private ?Throwable $throws) {}

        public function submitDocument(array $documentJson): array
        {
            if ($this->throws) {
                throw $this->throws;
            }

            return $this->resp;
        }
    });
}

beforeEach(function () {
    config(['eta.mock' => true]);
    $this->builder = app(EtaJsonBuilder::class);
});

/*
|--------------------------------------------------------------------------
| 1. JSON SHAPE
|--------------------------------------------------------------------------
*/

it('builds a document carrying the invoice totals, tax line and tenant tax_id', function () {
    [, , $tenant, , $invoice] = etaBusinessInvoice([], [
        'subtotal' => 20000, 'vat_amount' => 2800, 'total' => 22800,
    ]);
    $charge = Charge::create([
        'lease_id' => $invoice->lease_id, 'name' => 'Rent', 'type' => 'base_rent',
        'amount' => 20000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => true, 'vat_rate' => 14, 'start_date' => '2026-01-01', 'is_active' => true,
    ]);
    etaLine($invoice, $charge, 'Rent Feb', 'base_rent', 20000, 14);

    $doc = $this->builder->build($invoice->fresh(['lease.tenant', 'items.charge']));

    // Totals propagate from the invoice header.
    expect($doc['totalAmount'])->toBe(22800.0)
        ->and($doc['netAmount'])->toBe(20000.0)
        ->and($doc['totalSalesAmount'])->toBe(20000.0);
    // Tax line carries the header VAT under the T1 (VAT) tax type.
    expect($doc['taxTotals'])->toHaveCount(1)
        ->and($doc['taxTotals'][0]['taxType'])->toBe('T1')
        ->and($doc['taxTotals'][0]['amount'])->toBe(2800.0);
    // Receiver identity comes off the tenant: business type, tax_id, legal name.
    expect($doc['receiver']['type'])->toBe('B')
        ->and($doc['receiver']['id'])->toBe($tenant->tax_id)
        ->and($doc['receiver']['name'])->toBe('Acme Trading LLC');
    // Document framing.
    expect($doc['documentType'])->toBe('i')
        ->and($doc['internalID'])->toBe($invoice->number);
});

it('aggregates multiple invoice lines into one document, each with its own EGS code', function () {
    [, , , , $invoice] = etaBusinessInvoice([], [
        'subtotal' => 30000, 'vat_amount' => 4200, 'total' => 34200,
    ]);
    $rent = Charge::create([
        'lease_id' => $invoice->lease_id, 'name' => 'Rent', 'type' => 'base_rent',
        'amount' => 20000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => true, 'vat_rate' => 14, 'start_date' => '2026-01-01', 'is_active' => true,
    ]);
    $svc = Charge::create([
        'lease_id' => $invoice->lease_id, 'name' => 'Service', 'type' => 'service_charge',
        'amount' => 10000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => true, 'vat_rate' => 14, 'start_date' => '2026-01-01', 'is_active' => true,
    ]);
    etaLine($invoice, $rent, 'Rent Feb', 'base_rent', 20000, 14);
    etaLine($invoice, $svc, 'Service Feb', 'service_charge', 10000, 14);

    $doc = $this->builder->build($invoice->fresh(['lease.tenant', 'items.charge']));

    expect($doc['invoiceLines'])->toHaveCount(2);
    $codes = collect($doc['invoiceLines'])->pluck('itemCode')->all();
    expect($codes)->toContain('EG-6820-001')   // base_rent
        ->toContain('EG-6820-002');             // service_charge
    // Per-line money: line total = net + its own VAT.
    $rentLine = collect($doc['invoiceLines'])->firstWhere('internalCode', 'base_rent');
    expect($rentLine['netTotal'])->toBe(20000.0)
        ->and($rentLine['total'])->toBe(22800.0)
        ->and($rentLine['taxableItems'][0]['rate'])->toBe(14.0)
        ->and($rentLine['taxableItems'][0]['subType'])->toBe('V009');
});

it('rejects a business tenant that has no tax_id before anything hits ETA', function () {
    [, , $tenant, , $invoice] = etaBusinessInvoice(['tax_id' => null, 'type' => 'company']);
    expect($tenant->fresh()->tax_id)->toBeNull();

    expect(fn () => $this->builder->build($invoice->fresh(['lease.tenant', 'items.charge'])))
        ->toThrow(RuntimeException::class, 'has no tax_id');
});

it('allows an individual tenant with no tax_id and maps them to person type', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset, ['status' => 'occupied']);
    $tenant = makeTenant(['name' => 'Sara', 'type' => 'individual', 'tax_id' => null]);
    $lease = makeLease($unit, $tenant);
    $invoice = makeInvoice($lease, ['subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000]);
    etaLine($invoice, null, 'Rent', 'base_rent', 5000, 0);

    $doc = $this->builder->build($invoice->fresh(['lease.tenant', 'items.charge']));

    expect($doc['receiver']['type'])->toBe('P')
        ->and($doc['receiver']['id'])->toBe('000000000')   // tax_id fallback
        ->and($doc['taxTotals'])->toBe([]);                // no VAT -> empty tax totals
});

/*
|--------------------------------------------------------------------------
| 2. STATUS LIFECYCLE
|--------------------------------------------------------------------------
*/

it('starts an invoice unsubmitted with no ETA metadata', function () {
    [, , , , $invoice] = etaBusinessInvoice();

    expect($invoice->eta_status)->toBeNull()
        ->and($invoice->eta_submission_id)->toBeNull()
        ->and($invoice->eta_submitted_at)->toBeNull()
        ->and($invoice->eta_response)->toBeNull();
});

it('transitions unsubmitted -> valid on a successful submission and records the submission id', function () {
    [, , , , $invoice] = etaBusinessInvoice();
    etaLine($invoice, null, 'Rent', 'base_rent', 10000, 14);

    $updated = app(EtaSubmissionService::class)->submit($invoice->fresh());

    expect($updated->eta_status)->toBe('valid')                // mock returns documentStatus "Valid"
        ->and($updated->eta_submission_id)->not->toBeNull()
        ->and($updated->eta_submission_id)->toStartWith('MOCK-')
        ->and($updated->eta_long_id)->not->toBeNull()
        ->and($updated->eta_submitted_at)->not->toBeNull()
        ->and($updated->eta_response['acceptedDocuments'][0]['documentStatus'])->toBe('Valid');
});

it('persists status=submitted when ETA accepts the document but leaves it pending validation', function () {
    [, , , , $invoice] = etaBusinessInvoice();
    etaLine($invoice, null, 'Rent', 'base_rent', 10000, 14);

    // ETA accepts but documentStatus is "Submitted" (not yet Valid).
    bindEtaClient([
        'submissionId' => 'SUB-PENDING-1',
        'acceptedDocuments' => [[
            'uuid' => 'uuid-1', 'longId' => 'LONG-1', 'documentStatus' => 'Submitted',
        ]],
        'rejectedDocuments' => [],
    ]);

    $updated = app(EtaSubmissionService::class)->submit($invoice->fresh());

    expect($updated->eta_status)->toBe('submitted')
        ->and($updated->eta_submission_id)->toBe('SUB-PENDING-1')
        ->and($updated->eta_submitted_at)->not->toBeNull();
});

it('is idempotent: re-submitting an already-valid invoice is a no-op', function () {
    [, , , , $invoice] = etaBusinessInvoice();
    etaLine($invoice, null, 'Rent', 'base_rent', 10000, 14);

    $first = app(EtaSubmissionService::class)->submit($invoice->fresh());
    expect($first->eta_status)->toBe('valid');
    $firstId = $first->eta_submission_id;
    $firstAt = $first->eta_submitted_at;

    // A second submit must NOT mint a new submission id / timestamp.
    $second = app(EtaSubmissionService::class)->submit($first->fresh());

    expect($second->eta_status)->toBe('valid')
        ->and($second->eta_submission_id)->toBe($firstId)
        ->and($second->eta_submitted_at->toIso8601String())->toBe($firstAt->toIso8601String());
});

/*
|--------------------------------------------------------------------------
| 3. REJECTED SUBMISSION CARRIES THE ERROR
|--------------------------------------------------------------------------
*/

it('marks a rejected document as rejected and keeps ETAs rejection payload', function () {
    [, , , , $invoice] = etaBusinessInvoice();
    etaLine($invoice, null, 'Rent', 'base_rent', 10000, 14);

    bindEtaClient([
        'submissionId' => 'SUB-REJ-1',
        'acceptedDocuments' => [],
        'rejectedDocuments' => [[
            'internalId' => $invoice->number,
            'error' => ['code' => 'EINV', 'message' => 'Invalid tax registration number'],
        ]],
    ]);

    $updated = app(EtaSubmissionService::class)->submit($invoice->fresh());

    expect($updated->eta_status)->toBe('rejected')
        ->and($updated->eta_submitted_at)->not->toBeNull()
        ->and($updated->eta_response['rejectedDocuments'][0]['error']['message'])
        ->toBe('Invalid tax registration number');
});

it('records a transport failure as rejected and stores the exception message', function () {
    [, , , , $invoice] = etaBusinessInvoice();
    etaLine($invoice, null, 'Rent', 'base_rent', 10000, 14);

    bindEtaClient(throws: new RuntimeException('ETA endpoint timed out'));

    $updated = app(EtaSubmissionService::class)->submit($invoice->fresh());

    expect($updated->eta_status)->toBe('rejected')
        ->and($updated->eta_response['error'])->toBe('ETA endpoint timed out')
        ->and($updated->eta_submitted_at)->not->toBeNull();
});

it('lets a previously rejected invoice be re-submitted and reach valid', function () {
    [, , , , $invoice] = etaBusinessInvoice();
    etaLine($invoice, null, 'Rent', 'base_rent', 10000, 14);

    bindEtaClient(throws: new RuntimeException('temporary outage'));
    $rejected = app(EtaSubmissionService::class)->submit($invoice->fresh());
    expect($rejected->eta_status)->toBe('rejected');

    // Recover: a normal mock client now accepts it.
    app()->forgetInstance(EtaApiClient::class);
    app()->bind(EtaApiClient::class, fn () => new EtaApiClient);

    $recovered = app(EtaSubmissionService::class)->submit($rejected->fresh());

    expect($recovered->eta_status)->toBe('valid')
        ->and($recovered->eta_submission_id)->toStartWith('MOCK-');
});

/*
|--------------------------------------------------------------------------
| 4. RBAC — invoices.submit_to_eta gate
|--------------------------------------------------------------------------
*/

it('grants invoices.submit_to_eta to accounting (its owning department) and the cross-department super-roles', function () {
    $this->seed(RolesPermissionsSeeder::class);

    // accounting owns invoicing; manager + super_admin are cross-department
    // roles that hold every workflow action.
    expect(makeUser('accounting')->can('invoices.submit_to_eta'))->toBeTrue()
        ->and(makeUser('manager')->can('invoices.submit_to_eta'))->toBeTrue()
        ->and(makeUser('super_admin')->can('invoices.submit_to_eta'))->toBeTrue();
})->group('rbac');

it('denies invoices.submit_to_eta to read-only roles and every non-accounting department', function (string $role) {
    $this->seed(RolesPermissionsSeeder::class);

    expect(makeUser($role)->can('invoices.submit_to_eta'))->toBeFalse();
})->with(['viewer', 'owner', 'leasing', 'operations', 'marketing', 'hr'])->group('rbac');

it('does not let the accounting submit grant leak into delete or settings powers', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $accounting = makeUser('accounting');

    // It can submit, but the same role is NOT a super-power: no invoice delete.
    expect($accounting->can('invoices.submit_to_eta'))->toBeTrue()
        ->and($accounting->can('invoices.delete'))->toBeFalse()
        ->and($accounting->can('settings.manage'))->toBeFalse();
})->group('rbac');

/*
|--------------------------------------------------------------------------
| 5. SCOPING — ETA state stays on the submitted invoice
|--------------------------------------------------------------------------
*/

it('keeps ETA submission state isolated to the submitted invoice across properties', function () {
    [, , , , $invoiceA] = etaBusinessInvoice();
    etaLine($invoiceA, null, 'Rent A', 'base_rent', 10000, 14);
    [, , , , $invoiceB] = etaBusinessInvoice();
    etaLine($invoiceB, null, 'Rent B', 'base_rent', 10000, 14);

    app(EtaSubmissionService::class)->submit($invoiceA->fresh());

    // A is valid; B (a different property's invoice) is untouched.
    expect($invoiceA->fresh()->eta_status)->toBe('valid')
        ->and($invoiceB->fresh()->eta_status)->toBeNull()
        ->and($invoiceB->fresh()->eta_submission_id)->toBeNull();
});
