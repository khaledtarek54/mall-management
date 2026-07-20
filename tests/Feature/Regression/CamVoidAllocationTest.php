<?php

use App\Models\CamExpensePool;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerReportService;
use App\Services\CamReconciliationService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Carbon;

/**
 * voidAllocation — the correction path the freeze guard's error message promises ("void the billed
 * allocations first"). It reverses the recovery invoice / credit note / fee invoice, returns the
 * allocation to pending (unfreezing the pool basis), and keeps the books tied. A paid recovery
 * invoice blocks it (refund first).
 */
afterEach(fn () => Carbon::setTestNow());

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2027);
    Carbon::setTestNow('2027-01-15');
});

function camPool(float $actual, float $estimated): CamExpensePool
{
    $asset = makeAsset();
    makeLease(makeUnit($asset, ['area_sqm' => 100]), makeTenant());

    return CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2026,
        'total_actual_expense' => $actual, 'total_estimated_collected' => $estimated,
        'recovery_vat_rate' => 0, 'status' => 'draft',
    ]);
}

function camTb(): bool
{
    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    return app(LedgerReportService::class)->trialBalance()['balanced'];
}

it('voids a positive true-up: cancels the recovery invoice, resets to pending, books tie out', function () {
    $pool = camPool(50000, 30000); // +20000 true-up
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $billed = $svc->bill($pool->allocations()->sole());
    $invoice = InvoiceItem::where('charge_id', $billed->billed_charge_id)->first()->invoice;
    expect($invoice->status)->toBe('issued')->and(camTb())->toBeTrue();

    $svc->voidAllocation($billed->fresh());

    expect($invoice->fresh()->status)->toBe('cancelled')
        ->and($billed->fresh()->status)->toBe('pending')
        ->and($billed->fresh()->billed_charge_id)->toBeNull()
        ->and(camTb())->toBeTrue(); // recovery revenue + AR reversed, still balanced
});

it('unfreezes the pool basis after voiding — the actual-expense figure becomes editable again', function () {
    $pool = camPool(50000, 30000);
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $allocation = $svc->bill($pool->allocations()->sole());

    // While billed, the basis is frozen.
    expect(fn () => $pool->update(['total_actual_expense' => 60000]))->toThrow(DomainException::class);

    $svc->voidAllocation($allocation->fresh());

    // Now correctable — a late vendor invoice changed the actual; re-generate + re-bill follows.
    $pool->update(['total_actual_expense' => 60000]);
    expect((float) $pool->fresh()->total_actual_expense)->toBe(60000.0);
});

it('voids a negative true-up: un-applies + voids the credit note, re-opening the invoice it settled', function () {
    $pool = camPool(10000, 60000); // -50000 credit
    $lease = $pool->asset->units()->first()->leases()->first();
    // An open invoice for the lease so the CAM credit auto-applies (nets) against it.
    $open = makeInvoice($lease, ['status' => 'issued', 'subtotal' => 50000, 'vat_amount' => 0, 'total' => 50000, 'balance' => 50000, 'paid_amount' => 0]);

    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $billed = $svc->bill($pool->allocations()->sole());
    $noteId = $billed->billed_credit_note_id;
    expect((float) $open->fresh()->balance)->toBe(0.0) // credit netted the open invoice
        ->and($noteId)->not->toBeNull();

    $svc->voidAllocation($billed->fresh());

    expect($billed->fresh()->status)->toBe('pending')
        ->and($billed->fresh()->billed_credit_note_id)->toBeNull()          // ref cleared
        ->and(\App\Models\CreditNote::find($noteId)->status)->toBe('void')  // note voided (un-applied first)
        ->and((float) $open->fresh()->balance)->toBe(50000.0)               // invoice re-opened
        ->and(camTb())->toBeTrue();
});

it('refuses voiding when the recovery invoice was already paid (refund first)', function () {
    $pool = camPool(50000, 30000);
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $billed = $svc->bill($pool->allocations()->sole());
    $invoice = InvoiceItem::where('charge_id', $billed->billed_charge_id)->first()->invoice;

    // The tenant pays the recovery invoice.
    $payment = Payment::create([
        'tenant_id' => $invoice->tenant_id, 'amount' => 20000, 'currency' => 'EGP',
        'method' => 'cash', 'status' => 'captured', 'payment_date' => now()->toDateString(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 20000]);
    $invoice->recomputeTotals();

    expect(fn () => $svc->voidAllocation($billed->fresh()))->toThrow(DomainException::class);
});

it('reverts a RECONCILED pool to reconciling on void, so the correct-and-re-bill loop works', function () {
    $pool = camPool(50000, 30000);
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $billed = $svc->bill($pool->allocations()->sole());
    $pool->update(['status' => 'reconciled', 'reconciled_at' => now()]); // operator sealed it

    $svc->voidAllocation($billed->fresh());

    // Without this the pool stays 'reconciled', generateAllocations (blocked on reconciled) can't
    // re-run, and the operator is stuck re-billing the stale amount.
    expect($pool->fresh()->status)->toBe('reconciling')
        ->and($pool->fresh()->reconciled_at)->toBeNull();

    // The loop now completes: correct the actual, re-generate, re-bill.
    $pool->update(['total_actual_expense' => 60000]);
    expect($svc->generateAllocations($pool->fresh()))->toBe(1);
});

it('voids a positive true-up WITH an admin fee (one shared invoice) and the books still tie out', function () {
    $asset = makeAsset();
    makeLease(makeUnit($asset, ['area_sqm' => 100]), makeTenant());
    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2026,
        'total_actual_expense' => 50000, 'total_estimated_collected' => 30000,
        'admin_fee_pct' => 0.10, 'recovery_vat_rate' => 14, 'status' => 'draft', // fee + VAT both on
    ]);
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $billed = $svc->bill($pool->allocations()->sole());
    // Positive true-up + fee share ONE recovery invoice (two lines).
    $invoice = InvoiceItem::where('charge_id', $billed->billed_charge_id)->first()->invoice;
    expect($invoice->items()->count())->toBe(2)->and(camTb())->toBeTrue();

    $svc->voidAllocation($billed->fresh());

    expect($invoice->fresh()->status)->toBe('cancelled')        // the shared invoice voided once
        ->and($billed->fresh()->status)->toBe('pending')
        ->and($billed->fresh()->billed_admin_fee_charge_id)->toBeNull()
        ->and(camTb())->toBeTrue()                              // run the sweep so the GL reverses
        ->and(app(\App\Services\Reconciliation\BooksReconciliationService::class)->run()['ok'])->toBeTrue();
});

it('is a no-op on a pending (unbilled) allocation', function () {
    $pool = camPool(50000, 30000);
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $pending = $pool->allocations()->sole();

    $result = $svc->voidAllocation($pending);

    expect($result->status)->toBe('pending');
});
