<?php

/*
|--------------------------------------------------------------------------
| cam:reconcile — command-level lifecycle & idempotency
|--------------------------------------------------------------------------
| Drives App\Console\Commands\CamAnnualReconciliationCommand end-to-end via
| $this->artisan('cam:reconcile'). Asserts the command completes (exit 0) and
| is IDEMPOTENT + lock-safe: running it a second time must NOT double-reconcile
| (no extra allocations) nor double-bill (no duplicate true-up charges).
|
| Service-level allocation/billing math lives in
| tests/Feature/Scenarios/CamScenarioTest.php and
| tests/Feature/Services/CamAutoTrueUpTest.php — this file owns the CLI surface.
*/

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Charge;
use Illuminate\Support\Carbon;

// Freeze time so the "--year defaults to previous calendar year" branch is
// deterministic: now()->year - 1 must always resolve to 2025 here.
beforeEach(fn () => Carbon::setTestNow('2026-06-29 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

/**
 * Build one draft pool of $year over an asset with two active leases (100 + 300 sqm).
 *
 * The leases are dated to COVER $year. They used to take `makeLease`'s defaults — commencing
 * 2026-01-01 — while the pool reconciled 2025, so the fixture asked the system to recover a year's
 * common-area cost from two tenants who had not moved in yet. It passed, because the participant
 * test was `status = active` and said nothing about when the lease existed; a test green over an
 * impossible input is the whole reason that filter is now an occupancy overlap (2026-08-17).
 */
function camCommandFixture(int $year = 2025): CamExpensePool
{
    $asset = makeAsset();
    $covering = ['commencement_date' => "{$year}-01-01", 'expiry_date' => ($year + 3).'-12-31'];

    makeLease(makeUnit($asset, ['area_sqm' => 100]), attrs: $covering);
    makeLease(makeUnit($asset, ['area_sqm' => 300]), attrs: $covering);

    return CamExpensePool::create([
        'asset_id' => $asset->id,
        'period_year' => $year,
        'total_actual_expense' => 100000,
        'total_estimated_collected' => 80000,
        'status' => 'draft',
    ]);
}

/*
|--------------------------------------------------------------------------
| EMPTY / NO-OP
|--------------------------------------------------------------------------
*/

it('warns and exits SUCCESS when no pools exist for the requested year', function () {
    $this->artisan('cam:reconcile', ['--year' => 2099])
        ->expectsOutputToContain('No CAM pools found for 2099')
        ->assertExitCode(0);

    expect(CamAllocation::count())->toBe(0);
});

it('defaults to the previous calendar year when --year is omitted', function () {
    // Pool sits in 2025 (= now()->year - 1 under the frozen clock); the 2026
    // pool must be ignored so only the previous-year pool is reconciled.
    camCommandFixture(2025);
    camCommandFixture(2026);

    $this->artisan('cam:reconcile')
        ->expectsOutputToContain('2 allocations generated')
        ->assertExitCode(0);

    // Only the 2025 pool moved off 'draft'.
    expect(CamExpensePool::where('period_year', 2025)->value('status'))->toBe('reconciling');
    expect(CamExpensePool::where('period_year', 2026)->value('status'))->toBe('draft');
});

/*
|--------------------------------------------------------------------------
| REVIEW-ONLY (default) — generates allocations, no charges
|--------------------------------------------------------------------------
*/

it('generates pro-rata allocations and flips the pool to reconciling (review-only)', function () {
    $pool = camCommandFixture(2025);

    $this->artisan('cam:reconcile', ['--year' => 2025])
        ->expectsOutputToContain('2 allocations')
        ->assertExitCode(0);

    expect($pool->fresh()->status)->toBe('reconciling');
    expect($pool->allocations()->count())->toBe(2);
    expect($pool->allocations()->where('status', 'pending')->count())->toBe(2);

    // Review-only run must NOT bill anything.
    expect(Charge::where('type', 'cam_recovery')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| AUTO-BILL — full lifecycle to reconciled + true-up charges
|--------------------------------------------------------------------------
*/

it('with --auto-bill it bills every allocation and marks the pool reconciled', function () {
    $pool = camCommandFixture(2025);

    $this->artisan('cam:reconcile', ['--year' => 2025, '--auto-bill' => true])
        ->expectsOutputToContain('2 billed')
        ->assertExitCode(0);

    $pool->refresh();
    expect($pool->status)->toBe('reconciled');
    expect($pool->reconciled_at)->not->toBeNull();

    // One CAM true-up charge per lease, stamped with the period year.
    $charges = Charge::where('type', 'cam_recovery')->get();
    expect($charges)->toHaveCount(2);
    expect($charges->every(fn ($c) => str_contains($c->name, '2025')))->toBeTrue();

    // total actual (100000) - estimated (80000) = 20000 net true-up across the asset.
    expect((float) $charges->sum('amount'))->toBe(20000.0);
});

/*
|--------------------------------------------------------------------------
| IDEMPOTENCY — running the command twice must not double-reconcile
|--------------------------------------------------------------------------
*/

it('is idempotent in review-only mode: a second run does not duplicate allocations', function () {
    $pool = camCommandFixture(2025);

    $this->artisan('cam:reconcile', ['--year' => 2025])->assertExitCode(0);
    expect($pool->allocations()->count())->toBe(2);

    // Second run re-touches the same pending allocations in place (unique
    // pool+lease), never inserting new rows.
    $this->artisan('cam:reconcile', ['--year' => 2025])
        ->expectsOutputToContain('2 allocations')
        ->assertExitCode(0);

    expect(CamAllocation::count())->toBe(2);
    expect($pool->fresh()->status)->toBe('reconciling');
});

it('is idempotent + lock-safe with --auto-bill: a second run does not double-bill', function () {
    camCommandFixture(2025);

    // First run: bills both allocations, pool → reconciled.
    $this->artisan('cam:reconcile', ['--year' => 2025, '--auto-bill' => true])
        ->expectsOutputToContain('2 billed')
        ->assertExitCode(0);

    $chargeIds = Charge::where('type', 'cam_recovery')->pluck('id')->sort()->values();
    expect($chargeIds)->toHaveCount(2);

    // Second run: the reconciled pool is no longer eligible, so the year yields
    // an empty report — the command warns and still exits SUCCESS. Crucially no
    // new allocations and no second round of charges.
    $this->artisan('cam:reconcile', ['--year' => 2025, '--auto-bill' => true])
        ->expectsOutputToContain('No CAM pools found for 2025')
        ->assertExitCode(0);

    expect(CamAllocation::count())->toBe(2);
    expect(Charge::where('type', 'cam_recovery')->count())->toBe(2);
    // The exact same charge rows survive — nothing was re-created.
    expect(Charge::where('type', 'cam_recovery')->pluck('id')->sort()->values()->all())
        ->toBe($chargeIds->all());

    // Every allocation is terminal-billed and points at a charge.
    expect(CamAllocation::where('status', 'billed')->count())->toBe(2);
    expect(CamAllocation::whereNull('billed_charge_id')->count())->toBe(0);
});

it('a review-only run followed by an --auto-bill run bills the existing pending allocations once', function () {
    $pool = camCommandFixture(2025);

    // First: review-only → allocations pending, no charges.
    $this->artisan('cam:reconcile', ['--year' => 2025])->assertExitCode(0);
    expect($pool->fresh()->status)->toBe('reconciling');
    expect(Charge::where('type', 'cam_recovery')->count())->toBe(0);

    // Then: auto-bill picks up the SAME pending allocations (reconciling pools
    // are still eligible) and bills each exactly once.
    $this->artisan('cam:reconcile', ['--year' => 2025, '--auto-bill' => true])
        ->expectsOutputToContain('2 billed')
        ->assertExitCode(0);

    expect(CamAllocation::count())->toBe(2);
    expect(Charge::where('type', 'cam_recovery')->count())->toBe(2);
    expect($pool->fresh()->status)->toBe('reconciled');
});
