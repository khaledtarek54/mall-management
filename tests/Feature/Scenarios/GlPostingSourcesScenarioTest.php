<?php

use App\Models\DepositTransaction;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\MarketingBudget;
use App\Models\MarketingSpend;
use App\Models\Payroll;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerReportService;
use App\Services\DepositService;
use App\Services\ExpenseService;
use App\Services\PayrollService;
use App\Services\StockMovementService;
use App\Services\VendorBillService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Breadth scenario for the "GL integrity hardening" (Phases 0–5): every NON-invoice
 * posting source posts to the ledger and reverses through the REAL windowed/full
 * `accounting:sync-ledger` sweep, and the books tie out (trial balance balanced)
 * before AND after each reversal. Complements the narrow per-fix regression guards —
 * this asserts the whole posting SURFACE stays balanced, not one edge each.
 *
 * Near-real-time posting is off in the test env, so we drive the real command.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
});

/** Post everything via the real full sweep. (gps_ prefix keeps these file-unique.) */
function gps_sync(): void
{
    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();
}

/** The posted (non-void) entries for a source. */
function gps_postedEntries($source)
{
    return JournalEntry::where('source_type', $source->getMorphClass())
        ->where('source_id', $source->getKey())
        ->where('status', 'posted')
        ->get();
}

/** Count of VOIDED entries for a source. */
function gps_voidedCount($source): int
{
    return JournalEntry::where('source_type', $source->getMorphClass())
        ->where('source_id', $source->getKey())
        ->where('status', 'void')
        ->count();
}

function gps_balanced(): bool
{
    return app(LedgerReportService::class)->trialBalance()['balanced'];
}

it('posts a vendor bill + its payment then voids both when the bill is cancelled', function () {
    $asset = makeAsset();
    $bill = VendorBill::create([
        'vendor_id' => Vendor::factory()->create()->id,
        'asset_id' => $asset->id,
        'category' => 'utilities',
        'status' => 'approved',
        'bill_date' => now()->toDateString(),
        'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000, 'balance' => 5000,
    ]);
    // A partial payment (bill still has balance, so it can't be cancelled with a
    // payment on it — cancel after we reverse the payment).
    $payment = VendorBillPayment::create([
        'vendor_bill_id' => $bill->id, 'amount' => 2000,
        'method' => 'bank_transfer', 'payment_date' => now()->toDateString(),
    ]);

    gps_sync();

    expect(gps_postedEntries($bill))->toHaveCount(1);
    expect(gps_postedEntries($payment))->toHaveCount(1);
    expect(gps_balanced())->toBeTrue();

    // Reverse the payment (soft-delete self-heals its entry — Phase 0 F7), then cancel
    // the now-unpaid bill.
    $payment->delete();
    app(VendorBillService::class)->cancel($bill->refresh());

    gps_sync();

    expect(gps_voidedCount($bill))->toBe(1);
    expect(gps_voidedCount($payment))->toBe(1);
    expect(gps_postedEntries($bill))->toHaveCount(0);
    expect(gps_postedEntries($payment))->toHaveCount(0);
    expect(gps_balanced())->toBeTrue();
});

it('self-heals a vendor-bill payment to a voided entry on soft-delete while the bill stays posted', function () {
    // Phase 0 F7 in isolation: only the payment reverses; the bill's own entry stands.
    $asset = makeAsset();
    $bill = VendorBill::create([
        'vendor_id' => Vendor::factory()->create()->id,
        'asset_id' => $asset->id, 'category' => 'admin', 'status' => 'approved',
        'bill_date' => now()->toDateString(),
        'subtotal' => 3000, 'vat_amount' => 0, 'total' => 3000, 'balance' => 3000,
    ]);
    $payment = VendorBillPayment::create([
        'vendor_bill_id' => $bill->id, 'amount' => 3000,
        'method' => 'bank_transfer', 'payment_date' => now()->toDateString(),
    ]);

    gps_sync();
    expect(gps_postedEntries($payment))->toHaveCount(1);
    expect(gps_balanced())->toBeTrue();

    $payment->delete();
    gps_sync();

    expect(gps_voidedCount($payment))->toBe(1);
    expect(gps_postedEntries($bill))->toHaveCount(1); // the bill's own entry is untouched
    expect(gps_balanced())->toBeTrue();
});

it('posts a direct expense then voids it on cancel', function () {
    $asset = makeAsset();
    $expense = Expense::create([
        'asset_id' => $asset->id, 'category' => 'cleaning_security',
        'amount' => 4000, 'vat_amount' => 560, 'total' => 4560,
        'paid_from' => 'bank', 'expense_date' => now()->toDateString(), 'status' => 'recorded',
    ]);

    gps_sync();
    expect(gps_postedEntries($expense))->toHaveCount(1);
    expect(gps_balanced())->toBeTrue();

    app(ExpenseService::class)->cancel($expense);
    gps_sync();

    expect(gps_voidedCount($expense))->toBe(1);
    expect(gps_postedEntries($expense))->toHaveCount(0);
    expect(gps_balanced())->toBeTrue();
});

it('posts a payroll run then voids it on cancel', function () {
    $asset = makeAsset();
    $payroll = Payroll::create([
        'asset_id' => $asset->id, 'period_month' => now()->startOfMonth()->toDateString(),
        'gross_salaries' => 20000, 'salary_tax' => 2000, 'social_insurance' => 3000,
        'net_paid' => 15000, 'paid_from' => 'bank', 'status' => 'approved',
    ]);

    gps_sync();
    expect(gps_postedEntries($payroll))->toHaveCount(1);
    expect(gps_balanced())->toBeTrue();

    app(PayrollService::class)->cancel($payroll);
    gps_sync();

    expect(gps_voidedCount($payroll))->toBe(1);
    expect(gps_postedEntries($payroll))->toHaveCount(0);
    expect(gps_balanced())->toBeTrue();
});

it('posts a deposit receipt then voids it on cancel', function () {
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset));
    $deposit = DepositTransaction::create([
        'lease_id' => $lease->id, 'type' => 'receipt', 'amount' => 10000,
        'transaction_date' => now()->toDateString(), 'method' => 'bank', 'status' => 'recorded',
    ]);
    // tenant_id + asset_id are derived from the lease on save.
    expect($deposit->refresh()->asset_id)->toBe($asset->id);

    gps_sync();
    expect(gps_postedEntries($deposit))->toHaveCount(1);
    expect(gps_balanced())->toBeTrue();

    app(DepositService::class)->cancel($deposit);
    gps_sync();

    expect(gps_voidedCount($deposit))->toBe(1);
    expect(gps_postedEntries($deposit))->toHaveCount(0);
    expect(gps_balanced())->toBeTrue();
});

it('posts a marketing spend then voids it on soft-delete', function () {
    // MarketingSpend has no cancel_* — reversal is a soft-delete.
    $asset = makeAsset();
    $budget = MarketingBudget::create([
        'asset_id' => $asset->id, 'period_year' => (int) now()->year,
        'accrued_amount' => 50000, 'spent_amount' => 0, 'status' => 'open',
    ]);
    $spend = MarketingSpend::create([
        'marketing_budget_id' => $budget->id, 'category' => 'event',
        'description' => 'Launch event', 'amount' => 7000,
        'paid_from' => 'bank', 'spent_on' => now()->toDateString(),
    ]);

    gps_sync();
    expect(gps_postedEntries($spend))->toHaveCount(1);
    expect(gps_balanced())->toBeTrue();

    $spend->delete();
    gps_sync();

    expect(gps_voidedCount($spend))->toBe(1);
    expect(gps_postedEntries($spend))->toHaveCount(0);
    expect(gps_balanced())->toBeTrue();
});

it('posts a stock receipt then voids it on soft-delete', function () {
    // StockMovement has no cancel_* — reversal is a soft-delete. Always write through
    // the service so the sign is forced by type.
    $asset = makeAsset();
    $warehouse = Warehouse::create(['asset_id' => $asset->id, 'name' => 'Store', 'code' => 'S1']);
    $item = InventoryItem::create(['sku' => 'K1', 'name' => 'Cable', 'unit' => 'each', 'unit_cost' => 30]);
    $movement = app(StockMovementService::class)->receive($warehouse, $item, 40, 30);

    gps_sync();
    expect(gps_postedEntries($movement))->toHaveCount(1);
    expect(gps_balanced())->toBeTrue();

    $movement->delete();
    gps_sync();

    expect(gps_voidedCount($movement))->toBe(1);
    expect(gps_postedEntries($movement))->toHaveCount(0);
    expect(gps_balanced())->toBeTrue();
});

it('posts a fixed-asset acquisition then voids it on soft-delete', function () {
    // FixedAsset has no cancel_* — a mistaken asset is soft-deleted.
    $asset = makeAsset();
    $fa = FixedAsset::create([
        'asset_id' => $asset->id, 'name' => 'Generator', 'tag' => 'FA-G1',
        'acquisition_date' => now()->toDateString(), 'acquisition_cost' => 60000,
        'salvage_value' => 0, 'useful_life_months' => 60, 'method' => 'straight_line',
        'funded_from' => 'bank', 'status' => 'active',
    ]);

    gps_sync();
    expect(gps_postedEntries($fa))->toHaveCount(1);
    expect(gps_balanced())->toBeTrue();

    $fa->delete();
    gps_sync();

    expect(gps_voidedCount($fa))->toBe(1);
    expect(gps_postedEntries($fa))->toHaveCount(0);
    expect(gps_balanced())->toBeTrue();
});

it('re-homes a vendor bill and re-dimensions its payment entry on the windowed sweep (F9)', function () {
    // Phase 0 F9: the bill's asset_id is the books dimension of its child payment's GL.
    // Re-pointing the bill must flow to the payment on the DEFAULT (windowed) sweep,
    // which discovers sources by their own updated_at — the parent bump must reach it.
    $assetA = makeAsset();
    $assetB = makeAsset();
    $bill = VendorBill::create([
        'vendor_id' => Vendor::factory()->create()->id,
        'asset_id' => $assetA->id, 'category' => 'maintenance', 'status' => 'approved',
        'bill_date' => now()->toDateString(),
        'subtotal' => 8000, 'vat_amount' => 0, 'total' => 8000, 'balance' => 8000,
    ]);
    $payment = VendorBillPayment::create([
        'vendor_bill_id' => $bill->id, 'amount' => 8000,
        'method' => 'bank_transfer', 'payment_date' => now()->toDateString(),
    ]);

    gps_sync();
    $entry = gps_postedEntries($payment)->first();
    expect($entry->asset_id)->toBe($assetA->id);
    expect(JournalLine::where('journal_entry_id', $entry->id)->pluck('asset_id')->unique()->all())
        ->toBe([$assetA->id]);

    // Re-home the bill. Its `updated` hook bumps the payment's updated_at so the WINDOWED
    // sweep re-derives its dimension — no --all needed.
    $bill->update(['asset_id' => $assetB->id]);
    $this->artisan('accounting:sync-ledger')->assertSuccessful(); // DEFAULT windowed sweep

    $entry = gps_postedEntries($payment->refresh())->first();
    expect($entry->asset_id)->toBe($assetB->id);
    expect(JournalLine::where('journal_entry_id', $entry->id)->pluck('asset_id')->unique()->all())
        ->toBe([$assetB->id]);
    expect(gps_balanced())->toBeTrue();
});

it('re-homes a warehouse and re-dimensions its movement entry on the windowed sweep (F9)', function () {
    // Phase 0 F9, inventory side: the warehouse's asset_id is the movement entry's
    // dimension. Re-homing the warehouse bumps its movements so the windowed sweep
    // re-derives them.
    $assetA = makeAsset();
    $assetB = makeAsset();
    $warehouse = Warehouse::create(['asset_id' => $assetA->id, 'name' => 'WH', 'code' => 'W9']);
    $item = InventoryItem::create(['sku' => 'M1', 'name' => 'Filter', 'unit' => 'each', 'unit_cost' => 25]);
    $movement = app(StockMovementService::class)->receive($warehouse, $item, 20, 25);

    gps_sync();
    $entry = gps_postedEntries($movement)->first();
    expect($entry->asset_id)->toBe($assetA->id);

    $warehouse->update(['asset_id' => $assetB->id]);
    $this->artisan('accounting:sync-ledger')->assertSuccessful(); // windowed

    $entry = gps_postedEntries($movement->refresh())->first();
    expect($entry->asset_id)->toBe($assetB->id);
    expect(JournalLine::where('journal_entry_id', $entry->id)->pluck('asset_id')->unique()->all())
        ->toBe([$assetB->id]);
    expect(gps_balanced())->toBeTrue();
});
