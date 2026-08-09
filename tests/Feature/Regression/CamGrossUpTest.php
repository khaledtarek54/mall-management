<?php

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Services\CamReconciliationService;
use App\Services\Reconciliation\BooksReconciliationService;
use Carbon\CarbonImmutable;

/**
 * Gross-up (phase 6, story RC-04).
 *
 * RC-03's GLA denominator leaves vacancy with the landlord — correct, but it over-corrects on the
 * VARIABLE half of the pool. A mall at 40% occupancy spends less on cleaning and common-area power
 * than a full one, while its trading tenants consume those services at full intensity. Left alone
 * they pay 40% of a deflated number, which is LESS than they would pay in a busy mall, and the
 * landlord subsidises their consumption on top of its own vacancy.
 *
 * Two things must hold and both are pinned: **a tenant never pays more than they would in a full
 * centre**, and **the landlord never recovers more than it spent** — gross-up changes how a cost is
 * shared, not how much of it exists.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function grossUpAsset(): array
{
    // 400 m² trading out of 1,000 m² leasable — 40% occupancy.
    $asset = makeAsset(['leasable_area_sqm' => 1000]);

    $a = makeLease(makeUnit($asset, ['area_sqm' => 100]), null, [
        'status' => 'active', 'commencement_date' => '2027-01-01', 'expiry_date' => '2032-12-31',
    ]);
    $b = makeLease(makeUnit($asset, ['area_sqm' => 300]), null, [
        'status' => 'active', 'commencement_date' => '2027-01-01', 'expiry_date' => '2032-12-31',
    ]);

    return [$asset, $a->fresh(), $b->fresh()];
}

function grossUpPool(int $assetId, array $extra = []): CamExpensePool
{
    return CamExpensePool::create(array_merge([
        'asset_id' => $assetId,
        'period_year' => 2028,
        'status' => 'draft',
        'total_actual_expense' => 400000,
        'total_estimated_collected' => 0,
        'denominator_basis' => CamExpensePool::DENOMINATOR_GLA,
    ], $extra));
}

it('scales only the variable half up to the occupancy assumption', function () {
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $a] = grossUpAsset();

    // 50% variable, grossed to a 95% occupancy assumption at 40% actual occupancy.
    $pool = grossUpPool($asset->id, ['gross_up_pct' => 95, 'variable_pct' => 50]);

    app(CamReconciliationService::class)->generateAllocations($pool);

    // fixed 200,000 + variable 200,000 × (95 ÷ 40) = 200,000 + 475,000 = 675,000.
    expect((float) $pool->fresh()->grossed_up_expense)->toBe(675000.0);

    // This tenant holds 100 of 1,000 m², so 10% of the grossed basis.
    expect((float) CamAllocation::where('cam_expense_pool_id', $pool->id)->where('lease_id', $a->id)->sole()->allocated_amount)
        ->toBe(67500.0);
});

it('never recovers more than the landlord actually spent', function () {
    // The invariant that matters most. Gross-up changes how a cost is SHARED, not how much of it
    // exists — a landlord who recovered more than it spent would be billing tenants for nothing.
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset] = grossUpAsset();

    // The most aggressive case the settings allow: 100% variable, grossed to 100%.
    $pool = grossUpPool($asset->id, ['gross_up_pct' => 100, 'variable_pct' => 100]);
    app(CamReconciliationService::class)->generateAllocations($pool);

    $recovered = round((float) CamAllocation::where('cam_expense_pool_id', $pool->id)->sum('allocated_amount'), 2);

    expect($recovered)->toBeLessThanOrEqual(400000.0)
        ->and((float) $pool->fresh()->landlord_unrecovered_amount)->toBeGreaterThanOrEqual(0.0);
});

it('refuses to gross up on an occupied denominator, where it would over-recover', function () {
    // Under `occupied` the shares already sum to 100%, so grossing up would bill tenants MORE than
    // the landlord spent. Returning the ungrossed total is the honest answer.
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset] = grossUpAsset();

    $pool = grossUpPool($asset->id, [
        'denominator_basis' => CamExpensePool::DENOMINATOR_OCCUPIED,
        'gross_up_pct' => 95,
        'variable_pct' => 100,
    ]);
    app(CamReconciliationService::class)->generateAllocations($pool);

    expect((float) $pool->fresh()->grossed_up_expense)->toBe(400000.0)
        ->and(round((float) CamAllocation::where('cam_expense_pool_id', $pool->id)->sum('allocated_amount'), 2))
        ->toBe(400000.0)
        ->and((float) $pool->fresh()->landlord_unrecovered_amount)->toBe(0.0);
});

it('does nothing when the pool declares no variable portion', function () {
    // A pool that says nothing about its split is 0% variable, so gross-up is a no-op rather than
    // something arbitrary — and an unclassified pool must never quietly raise everyone's bill.
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset] = grossUpAsset();

    $pool = grossUpPool($asset->id, ['gross_up_pct' => 95]);
    app(CamReconciliationService::class)->generateAllocations($pool);

    expect((float) $pool->fresh()->grossed_up_expense)->toBe(400000.0);
});

it('never scales down a mall that is fuller than the clause contemplated', function () {
    // Occupancy above the assumption means the tenants' own shares already reflect a busy centre;
    // scaling down would hand them a discount the lease never promised.
    CarbonImmutable::setTestNow('2029-01-15');
    $asset = makeAsset(['leasable_area_sqm' => 1000]);
    makeLease(makeUnit($asset, ['area_sqm' => 980]), null, [
        'status' => 'active', 'commencement_date' => '2027-01-01', 'expiry_date' => '2032-12-31',
    ]);

    $pool = grossUpPool($asset->id, ['gross_up_pct' => 95, 'variable_pct' => 100]);
    app(CamReconciliationService::class)->generateAllocations($pool);

    expect((float) $pool->fresh()->grossed_up_expense)->toBe(400000.0);
});

it('keeps the books tie-out green with gross-up applied', function () {
    // The tie-out measures against what the landlord SPENT, never against the grossed basis.
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset] = grossUpAsset();

    $pool = grossUpPool($asset->id, ['gross_up_pct' => 95, 'variable_pct' => 50]);
    app(CamReconciliationService::class)->generateAllocations($pool);

    $report = app(BooksReconciliationService::class)->run();

    expect(collect($report['checks'])->firstWhere('key', 'cam_allocations')['passed'])->toBeTrue();
});

it('pushes a revised expense through on a re-run instead of reusing the old grossed basis', function () {
    // Found by CamScenarioTest. Reusing the stored `grossed_up_expense` on a re-run was the obvious
    // shortcut and it was wrong: the frozen-share guard freezes the AREA basis so a unit-area edit
    // cannot re-cut anybody's share, but a REVISED POOL EXPENSE is precisely what a re-run exists
    // to push through. The occupancy is taken from the frozen denominator; the money is not frozen.
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $a] = grossUpAsset();

    $pool = grossUpPool($asset->id, ['gross_up_pct' => 95, 'variable_pct' => 50]);
    app(CamReconciliationService::class)->generateAllocations($pool);

    expect((float) $pool->fresh()->grossed_up_expense)->toBe(675000.0);

    // A late vendor bill doubles the year's cost.
    $pool->update(['total_actual_expense' => 800000]);
    app(CamReconciliationService::class)->generateAllocations($pool->fresh());

    // fixed 400,000 + variable 400,000 × (95 ÷ 40) = 400,000 + 950,000.
    expect((float) $pool->fresh()->grossed_up_expense)->toBe(1350000.0)
        // …and the share is unchanged at 10%, because THAT is what the guard freezes.
        ->and((float) CamAllocation::where('cam_expense_pool_id', $pool->id)->where('lease_id', $a->id)->sole()->pro_rata_share_pct)
        ->toBe(10.0)
        ->and((float) CamAllocation::where('cam_expense_pool_id', $pool->id)->where('lease_id', $a->id)->sole()->allocated_amount)
        ->toBe(135000.0);
});

it('leaves every existing pool untouched, because gross_up_pct is null on all of them', function () {
    // The safety property. A pool created before RC-04 has no assumption, so nothing grosses.
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $a] = grossUpAsset();

    $pool = grossUpPool($asset->id);
    app(CamReconciliationService::class)->generateAllocations($pool);

    expect($pool->fresh()->gross_up_pct)->toBeNull()
        ->and((float) $pool->fresh()->grossed_up_expense)->toBe(400000.0)
        // 100 of 1,000 m² of the ungrossed pool.
        ->and((float) CamAllocation::where('cam_expense_pool_id', $pool->id)->where('lease_id', $a->id)->sole()->allocated_amount)
        ->toBe(40000.0);
});
