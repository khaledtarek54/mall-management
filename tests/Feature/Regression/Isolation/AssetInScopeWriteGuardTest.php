<?php

use App\Filament\Admin\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Admin\Resources\DepositTransactions\DepositTransactionResource;
use App\Filament\Admin\Resources\Expenses\ExpenseResource;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\OwnerRequests\OwnerRequestResource;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\Payrolls\PayrollResource;
use App\Filament\Admin\Resources\VendorBills\VendorBillResource;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Property-isolation write guard — regression for the 5 write-side gaps found in
 * the 2026-07 property-isolation audit. A property-restricted user must not be
 * able to create/edit a financial record into a property outside their assigned
 * set by tampering the client-supplied asset_id (the Select is enabled in
 * All-Properties mode). See docs/PROPERTY-ISOLATION.md
 *
 * Before the fix, ExpenseResource/VendorBillResource/PayrollResource/
 * JournalEntryResource/DepositTransactionResource had no assertAssetInScope guard,
 * so a tampered asset_id posted to another property's GL/AR books.
 */
$guarded = [
    'Expense' => ExpenseResource::class,
    'VendorBill' => VendorBillResource::class,
    'Payroll' => PayrollResource::class,
    'JournalEntry' => JournalEntryResource::class,
    'DepositTransaction' => DepositTransactionResource::class,
    'OwnerRequest' => OwnerRequestResource::class,
    'CreditNote' => CreditNoteResource::class,
];

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->assetA = makeAsset(['code' => 'ISOA']);
    $this->assetB = makeAsset(['code' => 'ISOB']);
});

it('rejects an out-of-scope property and allows the in-scope one', function (string $resource) {
    // A restricted user assigned only to property A.
    $this->actingAs(makeUser('manager', [$this->assetA->id]));

    // In-scope property: no exception.
    $resource::assertAssetInScope($this->assetA->id);
    expect(true)->toBeTrue();

    // Another property: blocked.
    expect(fn () => $resource::assertAssetInScope($this->assetB->id))
        ->toThrow(HttpException::class);

    // Consolidated (null) rows are portfolio-level — a restricted user can't create them.
    expect(fn () => $resource::assertAssetInScope(null))
        ->toThrow(HttpException::class);
})->with($guarded);

it('is a no-op for a portfolio user (super_admin)', function (string $resource) {
    $this->actingAs(makeUser('super_admin'));

    // Every property, including a consolidated (null) posting, is allowed.
    $resource::assertAssetInScope($this->assetA->id);
    $resource::assertAssetInScope($this->assetB->id);
    $resource::assertAssetInScope(null);
    expect(true)->toBeTrue();
})->with($guarded);

/**
 * Chain-derived resources (Invoice, Lease, TenantSalesDeclaration, TenantRequest,
 * Payment, CreditNote, DepositTransaction) determine their property from a client-supplied
 * lease/unit/invoice FK — a security sweep found these unguarded. The FK-resolving guards
 * must reject a foreign-property FK and allow an in-scope one.
 */
it('resolves and guards a chain-derived property (lease / unit / invoice)', function () {
    $this->actingAs(makeUser('manager', [$this->assetA->id]));

    $unitA = makeUnit($this->assetA);
    $unitB = makeUnit($this->assetB);
    $leaseA = makeLease($unitA);
    $leaseB = makeLease($unitB);
    $invoiceA = makeInvoice($leaseA);
    $invoiceB = makeInvoice($leaseB);

    // lease-derived (Invoice / TenantSalesDeclaration / CreditNote / DepositTransaction)
    InvoiceResource::assertLeaseAssetInScope($leaseA->id);
    expect(fn () => InvoiceResource::assertLeaseAssetInScope($leaseB->id))->toThrow(HttpException::class);

    // unit-derived (Lease master + additional units / TenantRequest)
    LeaseResource::assertUnitAssetInScope($unitA->id);
    expect(fn () => LeaseResource::assertUnitAssetInScope($unitB->id))->toThrow(HttpException::class);
    LeaseResource::assertUnitsAssetInScope([$unitA->id]);
    expect(fn () => LeaseResource::assertUnitsAssetInScope([$unitA->id, $unitB->id]))->toThrow(HttpException::class);

    // invoice-derived (Payment allocations)
    PaymentResource::assertInvoiceAssetInScope($invoiceA->id);
    expect(fn () => PaymentResource::assertInvoiceAssetInScope($invoiceB->id))->toThrow(HttpException::class);
});
