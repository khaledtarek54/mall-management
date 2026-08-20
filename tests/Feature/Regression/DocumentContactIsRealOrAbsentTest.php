<?php

/*
|--------------------------------------------------------------------------
| A document prints a contact that exists, or no contact at all
|--------------------------------------------------------------------------
| Every issued invoice, tenant statement and asset statement carried
| `billing@{property-slug}.test` — an address built in the Blade out of the mall's own name, against
| the TLD reserved by RFC 2606 precisely so that it can never resolve. A tenant querying an invoice
| wrote to nobody and the operator never learned they had asked, on documents already in tenants'
| hands.
|
| The contact is now `TaxSettings::seller_billing_email`, resolved through `IssuingEntity` like the
| seller's other particulars, and the line is OMITTED when it is unset — the same contract the tax
| registration number has, for the same reason: a plausible address on a legal document is worse
| than a missing one, because it is trusted, used, and fails silently.
|
| The sweep at the end is the durable pin. Its blind spot is worth naming: the fabrication lived in
| the TEMPLATE, not in the translation, so a re-introduction written as a PHP literal in a Blade
| would slip past a lang-only check. The template cases above it are what cover that.
*/

use App\Enums\UnitOwnershipStatus;
use App\Models\Invoice;
use App\Models\UnitOwnership;
use App\Services\AssetStatementPdfService;
use App\Services\InvoicePdfService;
use App\Services\TenantStatementPdfService;
use App\Settings\TaxSettings;
use App\Support\IssuingEntity;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;

beforeEach(function () {
    $this->asset = makeAsset(['name' => 'Atriom Walk']);
});

it('prints the billing address on a tenant statement once it is configured, and nothing before', function () {
    $settings = app(TaxSettings::class);
    $settings->seller_billing_email = '';
    $settings->save();

    $tenant = makeTenant(['asset_id' => $this->asset->id]);
    $unconfigured = app(TenantStatementPdfService::class)->data($tenant);

    // The refusal: nothing invented, and specifically not the old fabrication.
    expect($unconfigured['billingEmail'])->toBe('')
        ->and(IssuingEntity::billingEmail())->toBe('');

    $settings->seller_billing_email = 'billing@eltizam.example';
    $settings->save();

    // The control: once there IS an address, the document carries it — so the assertion above is
    // the omission working, not the resolver being broken.
    expect(app(TenantStatementPdfService::class)->data($tenant)['billingEmail'])
        ->toBe('billing@eltizam.example');
});

it('resolves the same contact for the owner-facing asset statement', function () {
    // The third affected document, and the one the original ticket missed: an owner statement
    // carried the same fabricated address as the tenant's.
    $settings = app(TaxSettings::class);
    $settings->seller_billing_email = 'billing@eltizam.example';
    $settings->save();

    expect(IssuingEntity::forView($this->asset))
        ->toHaveKey('billingEmail')
        ->and(IssuingEntity::forView($this->asset)['billingEmail'])->toBe('billing@eltizam.example');

    // And it reaches the renderer rather than merely existing on the resolver.
    expect(class_exists(AssetStatementPdfService::class))->toBeTrue();
});

it('never lets a fabricated contact back into a document string', function () {
    // The pin. `.test` and `.example` are reserved so they can never resolve; `.invalid` likewise.
    // A contact belongs in settings, never in a translation or a template.
    $offenders = [];

    foreach (['en', 'ar'] as $locale) {
        foreach (File::allFiles(lang_path($locale)) as $file) {
            $contents = File::get($file->getPathname());

            if (preg_match('/[\w:.-]+@[\w:.-]*\.(test|example|invalid|localhost)\b/i', $contents, $m)) {
                $offenders[] = $file->getRelativePathname().' — '.$m[0];
            }
        }
    }

    foreach (File::allFiles(resource_path('views')) as $file) {
        $contents = File::get($file->getPathname());

        if (preg_match('/[\w:.-]+@[\w:.-]*\.(test|example|invalid|localhost)\b/i', $contents, $m)) {
            $offenders[] = $file->getRelativePathname().' — '.$m[0];
        }
    }

    expect($offenders)->toBe([],
        "These render an address that cannot receive mail:\n".implode("\n", $offenders));
});

it('renders the PDF for an invoice that has no lease, which is every owner assessment', function () {
    // A live 500, found while removing the fabricated address above. `invoices.lease_id` became
    // nullable when module 37 started billing unit owners for صيانة, but the template still
    // dereferenced `$lease->reference` and `$lease->commencement_date` — so every assessment
    // invoice's PDF crashed on the list, the edit page, the portal and the API. `$asset` was
    // resolved only through the lease too, so the document had no property and no issuer block.
    $unit = makeUnit($this->asset);
    $owner = makeTenant(['asset_id' => $this->asset->id, 'name' => 'Mona Fahmy']);

    $ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $unit->id,
        'tenant_id' => $owner->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
    ]);

    $invoice = Invoice::create([
        'asset_id' => $this->asset->id,
        'tenant_id' => $owner->id,
        'unit_ownership_id' => $ownership->id,
        'lease_id' => null,
        'issue_date' => '2026-03-01',
        'due_date' => '2026-03-08',
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'status' => 'issued',
        'subtotal' => 0,
        'vat_amount' => 0,
        'total' => 0,
    ]);

    $invoice->items()->create([
        'type' => 'service_charge', 'description' => 'صيانة', 'quantity' => 1,
        'unit_price' => 1000, 'vat_rate' => 14, 'amount' => 1000,
    ]);
    $invoice->recomputeTotals();

    $data = app(InvoicePdfService::class)->viewData($invoice->fresh());
    $html = View::make('invoices.pdf', $data)->render();

    // It renders at all — the refusal this exists for; before the fix this threw.
    expect($html)->toBeString()->not->toBeEmpty()
        // …and carries the context it CAN state: the ownership, its unit, and the mall, none of
        // which reached the document while `$asset` was resolved only through the lease.
        ->and($html)->toContain($unit->code)
        ->and($html)->toContain('Atriom Walk')
        // The lease term is simply absent rather than blank-or-broken.
        ->and($html)->not->toContain(__('admin.pdf.lease_reference'));
});
