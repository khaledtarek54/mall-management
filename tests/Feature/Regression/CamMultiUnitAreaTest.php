<?php

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Services\CamReconciliationService;

/**
 * CAM must apportion on the lease's TOTAL leased area — every unit on the lease — not the master
 * unit's alone.
 *
 * Until 2026-08-08 `CamReconciliationService` read `$lease->unit->area_sqm` on BOTH sides: the
 * per-lease numerator and the pool denominator. Because both were wrong the same way, the shares
 * still summed to 100% and `Σ(allocated) = total_actual_expense` stayed green — so every tie-out
 * assertion in the suite passed while multi-unit leases were under-charged by their whole
 * non-master footprint and single-unit tenants silently absorbed the shortfall.
 *
 * That is why these tests assert the **share**, and assert the tie-out only to prove it is NOT the
 * thing that catches this. A tie-out cannot see a distribution error.
 *
 * Found by the Yardi benchmark — docs/benchmarks/yardi/04-scenarios.md S5.
 */
function makeCamPool(\App\Models\Asset $asset, array $attrs = []): CamExpensePool
{
    return CamExpensePool::create(array_merge([
        'asset_id' => $asset->id,
        'period_year' => 2026,
        'total_actual_expense' => 200000,
        'total_estimated_collected' => 0,
        'recovery_vat_rate' => 0,
        'status' => 'draft',
    ], $attrs));
}

it('apportions CAM on the summed area of a multi-unit lease, not the master unit alone', function () {
    $asset = makeAsset();

    // Lease A occupies TWO units: 900 + 300 = 1,200 m². Its master is the 900.
    $masterA = makeUnit($asset, ['area_sqm' => 900]);
    $extraA = makeUnit($asset, ['area_sqm' => 300]);
    $leaseA = makeLease($masterA);
    $leaseA->syncUnits([$masterA->id, $extraA->id], $masterA->id);

    // Lease B occupies ONE unit: 800 m².
    $leaseB = makeLease(makeUnit($asset, ['area_sqm' => 800]));

    // Denominator = 1,200 + 800 = 2,000 → A takes 60%, B takes 40%.
    $pool = makeCamPool($asset, ['total_actual_expense' => 200000]);

    app(CamReconciliationService::class)->generateAllocations($pool);

    $a = CamAllocation::where('cam_expense_pool_id', $pool->id)->where('lease_id', $leaseA->id)->sole();
    $b = CamAllocation::where('cam_expense_pool_id', $pool->id)->where('lease_id', $leaseB->id)->sole();

    // THE assertion. Pre-fix this read 52.9412 / 47.0588 (900÷1700, 800÷1700) — lease A short by
    // 7.06 points of the pool, lease B over-charged by the same, and the totals still tied out.
    expect((float) $a->pro_rata_share_pct)->toBe(60.0)
        ->and((float) $b->pro_rata_share_pct)->toBe(40.0);

    expect((float) $a->allocated_amount)->toBe(120000.0)
        ->and((float) $b->allocated_amount)->toBe(80000.0);
});

it('still ties the pool out exactly — which is precisely why the tie-out could not catch the bug', function () {
    $asset = makeAsset();

    $masterA = makeUnit($asset, ['area_sqm' => 900]);
    $extraA = makeUnit($asset, ['area_sqm' => 300]);
    $leaseA = makeLease($masterA);
    $leaseA->syncUnits([$masterA->id, $extraA->id], $masterA->id);

    makeLease(makeUnit($asset, ['area_sqm' => 800]));

    $pool = makeCamPool($asset, ['total_actual_expense' => 200000]);
    app(CamReconciliationService::class)->generateAllocations($pool);

    expect(round((float) $pool->allocations()->sum('allocated_amount'), 2))->toBe(200000.0)
        ->and(round((float) $pool->allocations()->sum('pro_rata_share_pct'), 4))->toBe(100.0);
});

it('freezes the corrected share on a re-run rather than recomputing it', function () {
    $asset = makeAsset();

    $masterA = makeUnit($asset, ['area_sqm' => 900]);
    $extraA = makeUnit($asset, ['area_sqm' => 300]);
    $leaseA = makeLease($masterA);
    $leaseA->syncUnits([$masterA->id, $extraA->id], $masterA->id);

    makeLease(makeUnit($asset, ['area_sqm' => 800]));

    $pool = makeCamPool($asset, ['total_actual_expense' => 200000]);
    $service = app(CamReconciliationService::class);
    $service->generateAllocations($pool);

    // An area edit between runs must NOT shift an established share (the frozen-basis guard).
    // Through RemeasureUnitService, and dated back into the pool's year: an area is a dated record
    // now, so a bare column edit is refused AND a remeasurement dated after the period would leave
    // areaOn(period) untouched — which would make this pass without exercising the freeze at all.
    app(\App\Services\RemeasureUnitService::class)->record($extraA, 5000, [
        'effective_from' => $pool->period_year.'-01-01',
    ]);
    $service->generateAllocations($pool->fresh());

    $a = CamAllocation::where('cam_expense_pool_id', $pool->id)->where('lease_id', $leaseA->id)->sole();
    expect((float) $a->pro_rata_share_pct)->toBe(60.0);
});

it('falls back to the master unit when a lease has no pivot rows', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset, ['area_sqm' => 450]);
    $lease = makeLease($unit);

    // Pre-observer / hand-built rows: no lease_unit rows at all.
    $lease->units()->detach();

    expect($lease->fresh()->totalAreaSqm())->toBe(450.0);
});
