<?php

use App\Models\CamExpensePool;
use App\Models\Lease;
use App\Services\CamReconciliationService;
use App\Services\RemeasureUnitService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * CAM must apportion a year on the area that was true DURING that year (2026-08-11).
 *
 * THE BUG. Unit-area versioning exists so that remeasuring a shop stops rewriting what was already
 * billed — `Unit::areaOn()` answers from the dated row in force. But CAM does not use it. Both the
 * numerator (`CamReconciliationService::generateAllocations`) and the occupied denominator call
 * `Lease::totalAreaSqmForPeriod()`, and that method time-weights how long each unit was HELD while
 * reading `$unit->area_sqm` — the denormalised CURRENT measurement. Its dated sibling
 * `totalAreaSqmOn()` does use `areaOn()`, so the two were split along the worst possible line: the
 * one CAM actually calls was the undated one.
 *
 * So reconciling 2025 for the first time in 2026, after any unit was remeasured, apportioned the
 * 2025 pool on 2026 areas.
 *
 * WHY NO EXISTING TEST CAUGHT IT. When every unit moves together the shares still sum to 100%, so
 * `Σ(allocated) = total_actual_expense` stays green and the tie-out sees nothing. It is the
 * DISTRIBUTION between tenants that is wrong — which is exactly the trap `Lease::totalAreaSqm()`'s
 * own docblock warns about: "assert the SHARE". These tests assert the share.
 *
 * (The re-run path is already safe — it freezes each allocation's stored `pro_rata_share_pct` — so
 * this only ever bit the FIRST reconciliation of a past year. That is also the run that bills.)
 */
function camAreaPool(int $year, float $expense = 100000): array
{
    $asset = makeAsset(['leasable_area_sqm' => 0]);

    $unitA = makeUnit($asset, ['code' => 'A-'.uniqid(), 'area_sqm' => 100]);
    $unitB = makeUnit($asset, ['code' => 'B-'.uniqid(), 'area_sqm' => 100]);

    $leaseA = makeLease($unitA, null, ['commencement_date' => "{$year}-01-01", 'expiry_date' => ($year + 2).'-12-31']);
    $leaseB = makeLease($unitB, null, ['commencement_date' => "{$year}-01-01", 'expiry_date' => ($year + 2).'-12-31']);

    $pool = CamExpensePool::create([
        'asset_id' => $asset->id,
        'name' => 'CAM '.$year,
        'period_year' => $year,
        'total_estimated_collected' => 0,
        'total_actual_expense' => $expense,
        'status' => 'draft',
    ]);

    return [$pool, $leaseA, $leaseB, $unitA, $unitB];
}

it('apportions a past year on the area in force THEN, not the area today', function () {
    // 2025: two identical 100 m² shops, so each carries half the pool.
    [$pool, $leaseA, $leaseB, $unitA] = camAreaPool(2025, 100000);

    // In 2026, shop A is remeasured to 300 m² — a knocked-through wall, recorded correctly with an
    // effective date, exactly as RemeasureUnitService is designed for.
    app(RemeasureUnitService::class)->record($unitA, 300, ['effective_from' => '2026-06-01']);

    // NOW reconcile 2025 for the first time.
    app(CamReconciliationService::class)->generateAllocations($pool->fresh());

    $shareA = (float) $pool->allocations()->where('lease_id', $leaseA->id)->value('pro_rata_share_pct');
    $shareB = (float) $pool->allocations()->where('lease_id', $leaseB->id)->value('pro_rata_share_pct');

    // Before the fix: 300/400 = 75% vs 25%, on a year in which both shops measured 100 m².
    expect(round($shareA, 2))->toBe(50.0)
        ->and(round($shareB, 2))->toBe(50.0);
});

it('keeps the tie-out green either way, which is why the share is what must be asserted', function () {
    [$pool, , , $unitA] = camAreaPool(2025, 100000);
    app(RemeasureUnitService::class)->record($unitA, 300, ['effective_from' => '2026-06-01']);

    app(CamReconciliationService::class)->generateAllocations($pool->fresh());

    // Σ(allocated) = the pool, with the WRONG distribution or the right one. This assertion is the
    // one the suite already had, and it cannot see the defect — recorded here so nobody later
    // mistakes it for coverage.
    expect(round((float) $pool->allocations()->sum('allocated_amount'), 2))
        ->toBe(100000.0);
});

it('still tracks a remeasurement that happened DURING the reconciled year', function () {
    // The other half: dating the area must not freeze it. A shop that grew in July of the year
    // being reconciled carries a time-weighted area for that year, not its January figure.
    [$pool, $leaseA, $leaseB, $unitA] = camAreaPool(2025, 100000);

    app(RemeasureUnitService::class)->record($unitA, 300, ['effective_from' => '2025-07-01']);

    app(CamReconciliationService::class)->generateAllocations($pool->fresh());

    $shareA = (float) $pool->allocations()->where('lease_id', $leaseA->id)->value('pro_rata_share_pct');
    $shareB = (float) $pool->allocations()->where('lease_id', $leaseB->id)->value('pro_rata_share_pct');

    // A: 100 m² for Jan–Jun (181 days) and 300 m² for Jul–Dec (184 days) = ~200.8 m² weighted.
    // B: a flat 100. So A carries roughly two thirds — more than half, and far less than the 75%
    // it would take if the new measurement applied to the whole year.
    expect($shareA)->toBeGreaterThan(60.0)->toBeLessThan(70.0)
        ->and(round($shareA + $shareB, 2))->toBe(100.0);
});

it('is unchanged for a lease whose units were never remeasured', function () {
    // The control. Every assertion above would pass just as well if the fix had broken the normal
    // case to zero, so the ordinary lease must still apportion exactly as it always did.
    [$pool, $leaseA, $leaseB] = camAreaPool(2025, 100000);

    app(CamReconciliationService::class)->generateAllocations($pool->fresh());

    expect(round((float) $pool->allocations()->where('lease_id', $leaseA->id)->value('pro_rata_share_pct'), 2))->toBe(50.0)
        ->and(round((float) $pool->allocations()->where('lease_id', $leaseB->id)->value('pro_rata_share_pct'), 2))->toBe(50.0);
});

it('reports the period area directly, so the defect has a unit-level witness', function () {
    [, $leaseA, , $unitA] = camAreaPool(2025);

    app(RemeasureUnitService::class)->record($unitA, 300, ['effective_from' => '2026-06-01']);

    $area = Lease::find($leaseA->id)->totalAreaSqmForPeriod(
        CarbonImmutable::parse('2025-01-01'),
        CarbonImmutable::parse('2025-12-31'),
    );

    expect(round($area, 2))->toBe(100.0); // not 300 — the 2026 measurement is not 2025's truth
});

it('dates the empty-pivot fallback too, which legacy rows are the ones to rely on it', function () {
    // `totalAreaSqmForPeriod` falls back to the MASTER unit when a lease has no `lease_unit` rows
    // — pre-observer data. That fallback still read the current `area_sqm` after the loop above was
    // fixed, so the oldest rows in the system, least likely to have been remeasured deliberately,
    // were the ones still answering a past period with today's walls.
    $asset = makeAsset(['leasable_area_sqm' => 0]);
    $unit = makeUnit($asset, ['code' => 'M-'.uniqid(), 'area_sqm' => 100]);
    $lease = makeLease($unit, null, ['commencement_date' => '2025-01-01', 'expiry_date' => '2027-12-31']);

    // Strip the pivot to reproduce a pre-observer lease.
    DB::table('lease_unit')->where('lease_id', $lease->id)->delete();

    app(RemeasureUnitService::class)->record($unit, 300, ['effective_from' => '2026-06-01']);

    $area = Lease::find($lease->id)->totalAreaSqmForPeriod(
        CarbonImmutable::parse('2025-01-01'),
        CarbonImmutable::parse('2025-12-31'),
    );

    expect(round($area, 2))->toBe(100.0)
        // …and the control: the CURRENT period still sees the new measurement, so dating the
        // fallback has not frozen it.
        ->and(round(Lease::find($lease->id)->totalAreaSqmForPeriod(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        ), 2))->toBe(300.0);
});
