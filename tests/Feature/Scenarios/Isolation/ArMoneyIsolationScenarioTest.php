<?php

use App\Filament\Admin\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Admin\Resources\DepositTransactions\DepositTransactionResource;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Models\Asset;
use App\Models\CreditNote;
use App\Models\DepositTransaction;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * AR money-in property isolation — Invoice / Payment / CreditNote / DepositTransaction.
 *
 * Complements (does not duplicate) tests/Feature/Regression/Isolation/AssetInScopeWriteGuardTest.php.
 * That regression covers the bare assert*AssetInScope() FK-guard contract; here we exercise the
 * READ-SCOPE (through scopedResourceQuery as a List page builds it), ALL-PROPERTIES visibility,
 * and — the group-specific rule — a SHARED tenant that leases in BOTH property A and B, proving
 * money-in is isolated PER-PROPERTY, not merely per-tenant.
 *
 * See docs/PROPERTY-ISOLATION.md and App\Support\PropertyIsolation.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->assetA = makeAsset(['code' => 'ARISA']);
    $this->assetB = makeAsset(['code' => 'ARISB']);

    $this->unitA = makeUnit($this->assetA);
    $this->unitB = makeUnit($this->assetB);

    // A SHARED tenant that leases in BOTH properties — the crux of the group-specific rule.
    $this->sharedTenant = makeTenant(['name' => 'Shared Retailer']);
    $this->leaseA = makeLease($this->unitA, $this->sharedTenant);
    $this->leaseB = makeLease($this->unitB, $this->sharedTenant);

    $this->invoiceA = makeInvoice($this->leaseA);
    $this->invoiceB = makeInvoice($this->leaseB);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/*
|--------------------------------------------------------------------------
| Helpers — build a real money-in record in a given property.
|--------------------------------------------------------------------------
*/

/** A captured payment allocated to the given invoice (money-in for that invoice's property). */
function makeArPayment(Invoice $invoice, array $attrs = []): Payment
{
    $payment = Payment::create(array_merge([
        'reference' => 'PAY-'.uniqid(),
        'tenant_id' => $invoice->tenant_id,
        'amount' => 11400,
        'currency' => 'EGP',
        'method' => 'bank_transfer',
        'status' => 'captured',
        'payment_date' => '2026-02-15',
    ], $attrs));

    $payment->invoices()->attach($invoice->id, ['allocated_amount' => (float) $payment->amount]);

    return $payment;
}

function makeArCreditNote(Invoice $invoice, ?int $leaseId, array $attrs = []): CreditNote
{
    return CreditNote::create(array_merge([
        'number' => 'CN-'.uniqid(),
        'tenant_id' => $invoice->tenant_id,
        'invoice_id' => $invoice->id,
        'lease_id' => $leaseId,
        'status' => 'issued',
        'issue_date' => '2026-02-20',
        'reason' => 'adjustment',
        'subtotal' => 1000,
        'vat_amount' => 140,
        'total' => 1140,
        'applied_amount' => 0,
        'balance' => 1140,
        'currency' => 'EGP',
    ], $attrs));
}

function makeArDeposit(Lease $lease, Asset $asset, array $attrs = []): DepositTransaction
{
    return DepositTransaction::create(array_merge([
        'number' => 'DEP-'.uniqid(),
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'asset_id' => $asset->id,
        'type' => 'receipt',
        'amount' => 5000,
        'transaction_date' => '2026-02-10',
        'method' => 'bank',
        'status' => 'recorded',
    ], $attrs));
}

/*
|--------------------------------------------------------------------------
| (a) READ-SCOPE — restricted user assigned to A sees only A's money-in.
|--------------------------------------------------------------------------
*/

it('scopes invoices to the active property for a restricted user', function () {
    $this->actingAs(makeUser('manager', [$this->assetA->id]));

    asTenant($this->assetA, function () {
        $ids = scopedResourceQuery(InvoiceResource::class)->pluck('id')->all();
        expect($ids)->toContain($this->invoiceA->id)
            ->and($ids)->not->toContain($this->invoiceB->id);
    });
});

it('scopes payments to the active property via the allocated invoice', function () {
    $this->actingAs(makeUser('manager', [$this->assetA->id]));

    $payA = makeArPayment($this->invoiceA);
    $payB = makeArPayment($this->invoiceB);

    asTenant($this->assetA, function () use ($payA, $payB) {
        $ids = scopedResourceQuery(PaymentResource::class)->pluck('id')->all();
        expect($ids)->toContain($payA->id)
            ->and($ids)->not->toContain($payB->id);
    });
});

it('scopes credit notes to the active property via lease.unit', function () {
    $this->actingAs(makeUser('manager', [$this->assetA->id]));

    $cnA = makeArCreditNote($this->invoiceA, $this->leaseA->id);
    $cnB = makeArCreditNote($this->invoiceB, $this->leaseB->id);

    asTenant($this->assetA, function () use ($cnA, $cnB) {
        $ids = scopedResourceQuery(CreditNoteResource::class)->pluck('id')->all();
        expect($ids)->toContain($cnA->id)
            ->and($ids)->not->toContain($cnB->id);
    });
});

it('scopes deposit transactions to the active property by asset_id dimension', function () {
    $this->actingAs(makeUser('manager', [$this->assetA->id]));

    $depA = makeArDeposit($this->leaseA, $this->assetA);
    $depB = makeArDeposit($this->leaseB, $this->assetB);

    asTenant($this->assetA, function () use ($depA, $depB) {
        $ids = scopedResourceQuery(DepositTransactionResource::class)->pluck('id')->all();
        expect($ids)->toContain($depA->id)
            ->and($ids)->not->toContain($depB->id);
    });
});

/*
|--------------------------------------------------------------------------
| Group-specific SHARED-vs-ISOLATED reads: a standalone (null-lease) credit
| note and a consolidated (null-asset) deposit are portfolio-level and visible
| in any property; they are NOT a cross-property leak.
|--------------------------------------------------------------------------
*/

it('shows a standalone (null-lease) credit note in any property but keeps lease-bound notes isolated', function () {
    $this->actingAs(makeUser('manager', [$this->assetA->id]));

    $standalone = makeArCreditNote($this->invoiceA, null); // tenant-level, no lease
    $bound = makeArCreditNote($this->invoiceB, $this->leaseB->id); // pinned to B

    asTenant($this->assetA, function () use ($standalone, $bound) {
        $ids = scopedResourceQuery(CreditNoteResource::class)->pluck('id')->all();
        expect($ids)->toContain($standalone->id)
            ->and($ids)->not->toContain($bound->id);
    });
});

it('shows a consolidated (null-asset) deposit in any property but keeps asset-bound ones isolated', function () {
    $this->actingAs(makeUser('manager', [$this->assetA->id]));

    $consolidated = makeArDeposit($this->leaseA, $this->assetA, ['asset_id' => null]);
    $boundToB = makeArDeposit($this->leaseB, $this->assetB);

    asTenant($this->assetA, function () use ($consolidated, $boundToB) {
        $ids = scopedResourceQuery(DepositTransactionResource::class)->pluck('id')->all();
        expect($ids)->toContain($consolidated->id)
            ->and($ids)->not->toContain($boundToB->id);
    });
});

/*
|--------------------------------------------------------------------------
| (b) ALL-PROPERTIES — portfolio sees BOTH; a restricted user in ALL mode
| is still pinned to their assigned set.
|--------------------------------------------------------------------------
*/

it('shows both properties money-in to a super_admin in All-Properties mode', function () {
    $this->actingAs(makeUser('super_admin'));

    $payA = makeArPayment($this->invoiceA);
    $payB = makeArPayment($this->invoiceB);
    $cnA = makeArCreditNote($this->invoiceA, $this->leaseA->id);
    $cnB = makeArCreditNote($this->invoiceB, $this->leaseB->id);
    $depA = makeArDeposit($this->leaseA, $this->assetA);
    $depB = makeArDeposit($this->leaseB, $this->assetB);

    asTenant(ensureAllPropertiesAsset(), function () use ($payA, $payB, $cnA, $cnB, $depA, $depB) {
        $invoiceIds = scopedResourceQuery(InvoiceResource::class)->pluck('id')->all();
        expect($invoiceIds)->toContain($this->invoiceA->id)->and($invoiceIds)->toContain($this->invoiceB->id);

        $paymentIds = scopedResourceQuery(PaymentResource::class)->pluck('id')->all();
        expect($paymentIds)->toContain($payA->id)->and($paymentIds)->toContain($payB->id);

        $cnIds = scopedResourceQuery(CreditNoteResource::class)->pluck('id')->all();
        expect($cnIds)->toContain($cnA->id)->and($cnIds)->toContain($cnB->id);

        $depIds = scopedResourceQuery(DepositTransactionResource::class)->pluck('id')->all();
        expect($depIds)->toContain($depA->id)->and($depIds)->toContain($depB->id);
    });
});

it('pins a restricted user to their assigned set even in All-Properties mode', function () {
    $this->actingAs(makeUser('manager', [$this->assetA->id]));

    $payA = makeArPayment($this->invoiceA);
    $payB = makeArPayment($this->invoiceB);
    $cnA = makeArCreditNote($this->invoiceA, $this->leaseA->id);
    $cnB = makeArCreditNote($this->invoiceB, $this->leaseB->id);
    $depA = makeArDeposit($this->leaseA, $this->assetA);
    $depB = makeArDeposit($this->leaseB, $this->assetB);

    asTenant(ensureAllPropertiesAsset(), function () use ($payA, $payB, $cnA, $cnB, $depA, $depB) {
        $invoiceIds = scopedResourceQuery(InvoiceResource::class)->pluck('id')->all();
        expect($invoiceIds)->toContain($this->invoiceA->id)->and($invoiceIds)->not->toContain($this->invoiceB->id);

        $paymentIds = scopedResourceQuery(PaymentResource::class)->pluck('id')->all();
        expect($paymentIds)->toContain($payA->id)->and($paymentIds)->not->toContain($payB->id);

        $cnIds = scopedResourceQuery(CreditNoteResource::class)->pluck('id')->all();
        expect($cnIds)->toContain($cnA->id)->and($cnIds)->not->toContain($cnB->id);

        $depIds = scopedResourceQuery(DepositTransactionResource::class)->pluck('id')->all();
        expect($depIds)->toContain($depA->id)->and($depIds)->not->toContain($depB->id);
    });
});

/*
|--------------------------------------------------------------------------
| (c) WRITE-GUARD — the resource's assert*AssetInScope() rejects out-of-scope
| and allows in-scope, for a restricted user.
|--------------------------------------------------------------------------
*/

it('rejects an out-of-scope lease and allows the in-scope one for Invoice / CreditNote / DepositTransaction', function () {
    $this->actingAs(makeUser('manager', [$this->assetA->id]));

    // In-scope: no throw.
    InvoiceResource::assertLeaseAssetInScope($this->leaseA->id);
    CreditNoteResource::assertLeaseAssetInScope($this->leaseA->id);
    DepositTransactionResource::assertLeaseAssetInScope($this->leaseA->id);
    expect(true)->toBeTrue();

    // Out-of-scope (property B): blocked.
    expect(fn () => InvoiceResource::assertLeaseAssetInScope($this->leaseB->id))->toThrow(HttpException::class);
    expect(fn () => CreditNoteResource::assertLeaseAssetInScope($this->leaseB->id))->toThrow(HttpException::class);
    expect(fn () => DepositTransactionResource::assertLeaseAssetInScope($this->leaseB->id))->toThrow(HttpException::class);
});

it('models the real null-lease (tenant-level) credit-note write path', function () {
    $this->actingAs(makeUser('manager', [$this->assetA->id]));

    // GROUP RULE ("null lease = tenant-level note is allowed") lives in the PAGE, not the
    // guard method. CreateCreditNote/EditCreditNote only call the guard when a lease is
    // chosen: `if (! empty($data['lease_id'])) { assertAssetInScope(...) }`. A null lease
    // simply skips the guard → the note is allowed. We assert that real write-path logic.
    $writePathAllows = function (?int $leaseId): bool {
        if (! empty($leaseId)) {
            CreditNoteResource::assertLeaseAssetInScope($leaseId); // may abort(403)
        }

        return true; // reached only when not blocked
    };

    // Null / empty lease: the guard is never invoked → allowed.
    expect($writePathAllows(null))->toBeTrue();

    // A lease in property A: allowed. A lease in property B: blocked.
    expect($writePathAllows($this->leaseA->id))->toBeTrue();
    expect(fn () => $writePathAllows($this->leaseB->id))->toThrow(HttpException::class);

    // NOTE / caveat: the guard METHOD itself does NOT treat null as "allowed" — a direct
    // CreditNoteResource::assertLeaseAssetInScope(null) resolves to a null asset and 403s
    // for a restricted user. The "null is allowed" behavior is entirely the page's
    // `if (! empty($lease_id))` wrapper. See bugsFound note.
    expect(fn () => CreditNoteResource::assertLeaseAssetInScope(null))->toThrow(HttpException::class);
});

it('rejects an out-of-scope invoice allocation and allows the in-scope one for Payment', function () {
    $this->actingAs(makeUser('manager', [$this->assetA->id]));

    PaymentResource::assertInvoiceAssetInScope($this->invoiceA->id);
    expect(true)->toBeTrue();

    expect(fn () => PaymentResource::assertInvoiceAssetInScope($this->invoiceB->id))->toThrow(HttpException::class);
});

/*
|--------------------------------------------------------------------------
| (d) GROUP-SPECIFIC — a SHARED tenant leases in BOTH A and B. Money-in for
| that tenant must be isolated PER-PROPERTY: a user restricted to A may
| allocate to the A invoice but NOT the B invoice, even though it's the same
| tenant. This is the crux — per-property, not merely per-tenant.
|--------------------------------------------------------------------------
*/

it('isolates a shared tenant per-property: the B invoice is blocked for a user restricted to A', function () {
    // Same tenant, one invoice in each property (built in beforeEach).
    expect($this->invoiceA->tenant_id)->toBe($this->invoiceB->tenant_id)
        ->and($this->invoiceA->tenant_id)->toBe($this->sharedTenant->id);

    $this->actingAs(makeUser('manager', [$this->assetA->id]));

    // The Payment allocation guard resolves invoice → lease → unit → asset. Same
    // tenant, but the B invoice belongs to property B → blocked; A invoice allowed.
    PaymentResource::assertInvoiceAssetInScope($this->invoiceA->id);
    expect(fn () => PaymentResource::assertInvoiceAssetInScope($this->invoiceB->id))
        ->toThrow(HttpException::class);

    // Likewise a credit note for the shared tenant: the B lease is out of scope.
    CreditNoteResource::assertLeaseAssetInScope($this->leaseA->id);
    expect(fn () => CreditNoteResource::assertLeaseAssetInScope($this->leaseB->id))
        ->toThrow(HttpException::class);
});

it('isolates a shared tenant per-property on READ: A sees only the A invoice for that tenant', function () {
    $this->actingAs(makeUser('manager', [$this->assetA->id]));

    // Money-in in both properties for the same shared tenant.
    $payA = makeArPayment($this->invoiceA);
    $payB = makeArPayment($this->invoiceB);

    asTenant($this->assetA, function () use ($payA, $payB) {
        // Even though both payments belong to the same tenant, only A's is visible.
        $paymentIds = scopedResourceQuery(PaymentResource::class)->pluck('id')->all();
        expect($paymentIds)->toContain($payA->id)->and($paymentIds)->not->toContain($payB->id);

        $invoiceIds = scopedResourceQuery(InvoiceResource::class)
            ->where('tenant_id', $this->sharedTenant->id)->pluck('id')->all();
        expect($invoiceIds)->toContain($this->invoiceA->id)->and($invoiceIds)->not->toContain($this->invoiceB->id);
    });
});
