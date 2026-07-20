<?php

use App\Models\CamExpensePool;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JournalLine;
use App\Services\CamReconciliationService;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerReportService;
use App\Services\Reconciliation\BooksReconciliationService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Carbon;

/**
 * CAM recovery-clause engine, slice 1 — the admin fee (operator decision: 10% of the recovered
 * cost share, 14% VAT, to its own cam_admin_fee_revenue account). The fee is a SIBLING of the
 * true-up: its own invoice line + is_active=false anchor charge, never folded into
 * true_up_amount — so allocated_amount (the books-check tie-out basis) is untouched. This test
 * drives the REAL bill() + the REAL `accounting:sync-ledger` sweep and asserts the trial balance
 * ties out and the fee lands in its dedicated revenue account, across all three true-up signs.
 */
afterEach(fn () => Carbon::setTestNow());

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2027); // recovery invoices issue in 2027 (period = 2026)
});

/** A single-lease pool at 10% admin fee; returns the billed allocation. */
function billWithFee(float $actual, float $estimated)
{
    Carbon::setTestNow('2027-01-15');
    $asset = makeAsset();
    makeLease(makeUnit($asset, ['area_sqm' => 100]), makeTenant());
    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2026,
        'total_actual_expense' => $actual, 'total_estimated_collected' => $estimated,
        'admin_fee_pct' => 0.10, 'admin_fee_on_net' => true, 'recovery_vat_rate' => 0, 'status' => 'draft',
    ]);
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);

    return $svc->bill($pool->allocations()->sole());
}

function feeRevenueCredited(): float
{
    return (float) JournalLine::whereHas('account', fn ($q) => $q->where('code', '41108001'))->sum('credit');
}

function trialBalanced(): bool
{
    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    return app(LedgerReportService::class)->trialBalance()['balanced'];
}

it('computes a 10% fee + 14% VAT on the allocation', function () {
    $billed = billWithFee(50000, 30000); // allocated 50000
    // Fee is on the recovered COST share (allocated), not the true-up: 10% × 50000 = 5000.
    expect((float) $billed->admin_fee_amount)->toBe(5000.0)
        ->and((float) $billed->admin_fee_vat_amount)->toBe(700.0);
});

it('rides a positive true-up on the SAME recovery invoice as a second VAT line', function () {
    $billed = billWithFee(50000, 30000); // +20000 true-up, +5000 fee

    expect($billed->billed_charge_id)->not->toBeNull()
        ->and($billed->billed_admin_fee_charge_id)->not->toBeNull();

    // Both lines on ONE invoice: cost 20000 (VAT 0) + fee 5000 (VAT 700) = 25700.
    $invoice = InvoiceItem::where('charge_id', $billed->billed_charge_id)->first()->invoice;
    $types = $invoice->items->pluck('type')->sort()->values()->all();
    expect($types)->toBe(['cam_admin_fee', 'cam_recovery'])
        ->and((float) $invoice->subtotal)->toBe(25000.0)
        ->and((float) $invoice->vat_amount)->toBe(700.0)
        ->and((float) $invoice->total)->toBe(25700.0);

    expect(trialBalanced())->toBeTrue()
        ->and(feeRevenueCredited())->toBe(5000.0)
        ->and(app(BooksReconciliationService::class)->run()['ok'])->toBeTrue();
});

it('bills the fee on its own invoice when the true-up is a credit', function () {
    $billed = billWithFee(30000, 50000); // -20000 credit, allocated 30000 → +3000 fee

    expect($billed->billed_credit_note_id)->not->toBeNull()
        ->and($billed->billed_admin_fee_charge_id)->not->toBeNull();

    // Fee-only invoice: 3000 + 420 VAT = 3420 (the credit rides a separate credit note).
    $feeInvoice = InvoiceItem::where('charge_id', $billed->billed_admin_fee_charge_id)->first()->invoice;
    expect($feeInvoice->items)->toHaveCount(1)
        ->and((float) $feeInvoice->total)->toBe(3420.0);

    expect(trialBalanced())->toBeTrue()
        ->and(feeRevenueCredited())->toBe(3000.0)
        ->and(app(BooksReconciliationService::class)->run()['ok'])->toBeTrue();
});

it('bills the fee on its own invoice when the true-up is exactly zero', function () {
    $billed = billWithFee(40000, 40000); // 0 true-up, allocated 40000 → +4000 fee

    expect($billed->billed_charge_id)->toBeNull()
        ->and($billed->billed_credit_note_id)->toBeNull()
        ->and($billed->billed_admin_fee_charge_id)->not->toBeNull();

    $feeInvoice = InvoiceItem::where('charge_id', $billed->billed_admin_fee_charge_id)->first()->invoice;
    expect((float) $feeInvoice->total)->toBe(4560.0); // 4000 + 560 VAT

    expect(trialBalanced())->toBeTrue()
        ->and(feeRevenueCredited())->toBe(4000.0)
        ->and(app(BooksReconciliationService::class)->run()['ok'])->toBeTrue();
});
