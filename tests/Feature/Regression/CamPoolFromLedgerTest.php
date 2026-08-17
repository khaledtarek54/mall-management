<?php

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Lease;
use App\Models\LedgerAccount;
use App\Services\ApplyCamEstimateService;
use App\Services\CamReconciliationService;
use App\Services\ChargeScheduleService;
use App\Services\SyncCamPoolFromLedgerService;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * The recovery pool stops being two numbers somebody typed (phase 6, stories RC-01 and RC-05).
 *
 * `total_actual_expense` was re-keyed from a spreadsheet, so no tenant charge could drill to the
 * bills behind it. `total_estimated_collected` was one portfolio figure sliced pro-rata, so
 * `estimated_paid` was never what a tenant actually paid. And nothing ever moved the monthly
 * estimate, so the reconciliation discovered the same shortfall every year.
 *
 * The safety property matters as much as the feature: **both bases default to `stated`**, so every
 * pool that already exists reconciles exactly as it did. That is pinned below, because getting it
 * wrong would restate closed years.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

// The ledger-sourced tests need a real chart of accounts to post into.
beforeEach(fn () => test()->seed(ChartOfAccountsSeeder::class));

function camPoolAsset(): array
{
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset, ['area_sqm' => 100]), null, [
        'status' => 'active',
        'commencement_date' => '2027-01-01',
        'expiry_date' => '2032-12-31',
        'base_rent_monthly' => 50000,
        'service_charge_monthly' => 5000,
        'has_marketing_levy' => false,
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Service Charge', 'type' => 'service_charge',
        'origin' => Charge::ORIGIN_SEED, 'amount' => 5000, 'currency' => 'EGP',
        'frequency' => 'monthly', 'vat_applicable' => true, 'vat_rate' => Vat::standardRate(),
        'start_date' => '2027-01-01', 'is_active' => true,
    ]);

    return [$asset, $lease->fresh()];
}

function postExpense(int $assetId, int $accountId, float $amount, string $date, string $status = 'posted'): void
{
    // Built in the order the product builds one: the lines go on while the entry is a DRAFT,
    // then it is posted. Hand-crafting lines onto an already-posted entry is a state production
    // cannot reach and `JournalLine`'s immutability guard now refuses (module 21 close-out).
    $entry = JournalEntry::create([
        'entry_date' => $date,
        'description_en' => 'Cleaning contractor',
        'status' => 'draft',
        'asset_id' => $assetId,
        'is_manual' => true,
    ]);

    JournalLine::create([
        'journal_entry_id' => $entry->id,
        'ledger_account_id' => $accountId,
        'debit' => $amount,
        'credit' => 0,
        'asset_id' => $assetId,
    ]);

    if ($status !== 'draft') {
        $entry->update(['status' => $status]);
    }
}

it('sums the pool from posted ledger lines on its own accounts and property', function () {
    [$asset] = camPoolAsset();
    $other = makeAsset();
    $account = LedgerAccount::where('is_postable', true)->where('type', 'expense')->firstOrFail();

    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2028, 'status' => 'draft',
        'total_actual_expense' => 0, 'total_estimated_collected' => 0,
        'expense_basis' => CamExpensePool::BASIS_LEDGER,
    ]);
    $pool->ledgerAccounts()->attach($account->id);

    postExpense($asset->id, $account->id, 400000, '2028-03-15');
    postExpense($asset->id, $account->id, 100000, '2028-09-15');
    postExpense($asset->id, $account->id, 999999, '2027-03-15');            // wrong year
    postExpense($other->id, $account->id, 888888, '2028-03-15');            // the mall next door
    postExpense($asset->id, $account->id, 777777, '2028-04-15', 'draft');   // not posted yet

    app(SyncCamPoolFromLedgerService::class)->sync($pool);

    expect((float) $pool->fresh()->total_actual_expense)->toBe(500000.0)
        ->and($pool->fresh()->expense_synced_at)->not->toBeNull();
});

it('nets vendor credits off the pool rather than recovering them from tenants', function () {
    // A debits-only sum would recover money the landlord has already been refunded.
    [$asset] = camPoolAsset();
    $account = LedgerAccount::where('is_postable', true)->where('type', 'expense')->firstOrFail();

    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2028, 'status' => 'draft',
        'total_actual_expense' => 0, 'total_estimated_collected' => 0,
        'expense_basis' => CamExpensePool::BASIS_LEDGER,
    ]);
    $pool->ledgerAccounts()->attach($account->id);

    postExpense($asset->id, $account->id, 500000, '2028-03-15');

    // Draft first, then posted — see postExpense() above for why.
    $entry = JournalEntry::create([
        'entry_date' => '2028-06-01', 'description_en' => 'Contractor credit note',
        'status' => 'draft', 'asset_id' => $asset->id, 'is_manual' => true,
    ]);
    JournalLine::create([
        'journal_entry_id' => $entry->id, 'ledger_account_id' => $account->id,
        'debit' => 0, 'credit' => 50000, 'asset_id' => $asset->id,
    ]);
    $entry->update(['status' => 'posted']);

    app(SyncCamPoolFromLedgerService::class)->sync($pool);

    expect((float) $pool->fresh()->total_actual_expense)->toBe(450000.0);
});

it('refuses to re-source a reconciled pool, because its allocations are already billed', function () {
    [$asset] = camPoolAsset();
    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2028, 'status' => 'reconciled',
        'total_actual_expense' => 500000, 'total_estimated_collected' => 400000,
        'expense_basis' => CamExpensePool::BASIS_LEDGER,
    ]);

    expect(fn () => app(SyncCamPoolFromLedgerService::class)->sync($pool))
        ->toThrow(InvalidArgumentException::class);

    expect((float) $pool->fresh()->total_actual_expense)->toBe(500000.0);
});

it('leaves a stated pool exactly as it was — the safety property', function () {
    // Every pool that existed before RC-01 is `stated` on both bases. If a sync touched one, closed
    // years would be restated from a ledger they were never reconciled against.
    [$asset] = camPoolAsset();
    $account = LedgerAccount::where('is_postable', true)->where('type', 'expense')->firstOrFail();
    postExpense($asset->id, $account->id, 999999, '2028-03-15');

    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2028, 'status' => 'draft',
        'total_actual_expense' => 500000, 'total_estimated_collected' => 400000,
    ]);
    $pool->ledgerAccounts()->attach($account->id);

    $result = app(SyncCamPoolFromLedgerService::class)->sync($pool);

    expect($result['expense'])->toBeNull()
        ->and($result['estimate'])->toBeNull()
        ->and((float) $pool->fresh()->total_actual_expense)->toBe(500000.0)
        ->and($pool->fresh()->expense_synced_at)->toBeNull();
});

it('makes the estimate reconciled the same number as the estimate billed', function () {
    // The open loop. On `stated`, estimated_paid is a pro-rata slice of a figure a human kept
    // roughly equal to the billing; on `billed` it IS the billing.
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $lease] = camPoolAsset();

    // Three months invoiced at 5,000 of service charge.
    foreach (['2028-01-01', '2028-02-01', '2028-03-01'] as $month) {
        $invoice = makeInvoice($lease, [
            'period_start' => $month,
            'period_end' => CarbonImmutable::parse($month)->endOfMonth()->toDateString(),
            'status' => 'issued',
        ]);
        $invoice->items()->delete();
        $invoice->items()->create([
            'description' => 'Service Charge', 'type' => 'service_charge',
            'amount' => 5000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 5000,
        ]);
    }

    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2028, 'status' => 'draft',
        'total_actual_expense' => 240000,
        // Deliberately WRONG, to prove the billed basis ignores it.
        'total_estimated_collected' => 999999,
        'estimate_basis' => CamExpensePool::BASIS_BILLED,
    ]);

    app(CamReconciliationService::class)->generateAllocations($pool);

    $allocation = CamAllocation::where('cam_expense_pool_id', $pool->id)->sole();

    expect((float) $allocation->estimated_paid)->toBe(15000.0)            // 3 × 5,000
        ->and((float) $allocation->allocated_amount)->toBe(240000.0)
        ->and((float) $allocation->true_up_amount)->toBe(225000.0);       // 240,000 − 15,000
});

it('proposes next year’s monthly estimate from what the year actually cost', function () {
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $lease] = camPoolAsset();

    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2028, 'status' => 'draft',
        'total_actual_expense' => 240000, 'total_estimated_collected' => 60000,
    ]);

    app(CamReconciliationService::class)->generateAllocations($pool);

    // 240,000 ÷ 12. The tenant was paying 5,000 and the space actually cost 20,000 a month — the
    // shortfall that repeated every year because nothing moved the estimate.
    expect((float) CamAllocation::where('cam_expense_pool_id', $pool->id)->sole()->proposed_monthly_estimate)
        ->toBe(20000.0);
});

it('applies an accepted estimate as a schedule row effective next January, not as an overwrite', function () {
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $lease] = camPoolAsset();

    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2028, 'status' => 'draft',
        'total_actual_expense' => 240000, 'total_estimated_collected' => 60000,
    ]);
    app(CamReconciliationService::class)->generateAllocations($pool);
    $pool->update(['status' => 'reconciled']);

    $result = app(ApplyCamEstimateService::class)->applyForPool($pool->fresh());

    expect($result['applied'])->toBe(1)
        ->and($result['effective_from']->toDateString())->toBe('2029-01-01');

    $schedule = app(ChargeScheduleService::class);

    // 2028 still bills what it billed; 2029 bills the re-based figure. That is the whole point of
    // the schedule model — a re-estimate is a new row, never an overwrite.
    expect((float) $schedule->rowInForce($lease->fresh(), 'service_charge', CarbonImmutable::parse('2028-06-01'))->amount)
        ->toBe(5000.0)
        ->and((float) $schedule->rowInForce($lease->fresh(), 'service_charge', CarbonImmutable::parse('2029-06-01'))->amount)
        ->toBe(20000.0)
        // …and it lands on the lease's history with a reason, like every other rent-affecting change.
        ->and($lease->fresh()->events()->first()->reason)->toContain('2028');
});

it('applies once, however many times the action is run', function () {
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $lease] = camPoolAsset();

    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2028, 'status' => 'draft',
        'total_actual_expense' => 240000, 'total_estimated_collected' => 60000,
    ]);
    app(CamReconciliationService::class)->generateAllocations($pool);
    $pool->update(['status' => 'reconciled']);

    app(ApplyCamEstimateService::class)->applyForPool($pool->fresh());
    $second = app(ApplyCamEstimateService::class)->applyForPool($pool->fresh());

    expect($second['applied'])->toBe(0)
        ->and($second['skipped'])->toBe(1)
        // One schedule row for 2029, not two — the overlap guard would have caught a second, but
        // the point is that the action does not try.
        ->and(Charge::where('lease_id', $lease->id)->where('type', 'service_charge')->where('is_active', true)->count())
        ->toBe(2);
});

it('refuses to re-estimate from a year that has not been reconciled', function () {
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset] = camPoolAsset();

    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2028, 'status' => 'draft',
        'total_actual_expense' => 240000, 'total_estimated_collected' => 60000,
    ]);

    expect(fn () => app(ApplyCamEstimateService::class)->applyForPool($pool))
        ->toThrow(InvalidArgumentException::class);
});

it('skips a lease that has ended rather than resurrecting its billing', function () {
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $lease] = camPoolAsset();

    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2028, 'status' => 'draft',
        'total_actual_expense' => 240000, 'total_estimated_collected' => 60000,
    ]);
    app(CamReconciliationService::class)->generateAllocations($pool);
    $pool->update(['status' => 'reconciled']);

    $lease->update(['status' => 'terminated']);

    $result = app(ApplyCamEstimateService::class)->applyForPool($pool->fresh());

    expect($result['applied'])->toBe(0)
        ->and($result['skipped'])->toBe(1);
});
