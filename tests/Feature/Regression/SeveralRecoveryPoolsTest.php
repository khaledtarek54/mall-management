<?php

use App\Models\Area;
use App\Models\Asset;
use App\Models\CamExpensePool;
use App\Models\Lease;
use App\Services\CamReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Several recovery pools per property-year (phase 8, story RC-02).
 *
 * **What one pool could not say.** Yardi's model is many named pools on a property — CAM, real
 * estate tax, insurance, utilities, security, HVAC — each with its own participants, basis, admin
 * fee and cap (03-yardi-recoveries-percentage-rent.md §A2). Atriom allowed exactly one per
 * `(asset_id, period_year)`, so its own cited example was unrepresentable: *everyone shares CAM, but
 * only the food court shares grease-trap cleaning*. The cost either went into the single pool and
 * was charged to tenants who never used it, or it stayed with the landlord.
 *
 * **Participants are scoped by AREA, not by a hand-kept list.** `units.area_id` already exists and
 * the Yardi example is literally a zone. Reading the participant set from real data means it
 * re-answers on its own when a lease moves units, where a list would go stale in a way nobody would
 * notice until a tenant was billed for a pool they had left.
 *
 * **`pool_code` defaults to `cam` and the scope to `all`**, so every pool written before this
 * behaves exactly as it did — which the first test pins.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function poolFor(Asset $asset, array $attrs = []): CamExpensePool
{
    return CamExpensePool::create(array_merge([
        'asset_id' => $asset->id,
        'period_year' => 2026,
        'total_actual_expense' => 100000,
        'expense_basis' => CamExpensePool::BASIS_STATED,
        'estimate_basis' => CamExpensePool::BASIS_STATED,
        'status' => 'draft',
    ], $attrs));
}

function activeLeaseIn(Asset $asset, string $code, float $area, ?Area $zone = null): Lease
{
    $unit = makeUnit($asset, ['code' => $code, 'area_sqm' => $area, 'area_id' => $zone?->id]);

    return makeLease($unit, null, [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2028-12-31',
    ])->fresh();
}

it('keeps a pool with no code behaving exactly as it did', function () {
    // The whole property participates and the code defaults to `cam` — the single-pool behaviour.
    $asset = makeAsset();
    activeLeaseIn($asset, 'P-1', 100);
    activeLeaseIn($asset, 'P-2', 100);

    $pool = poolFor($asset);

    expect($pool->pool_code)->toBe(CamExpensePool::CODE_CAM)
        ->and($pool->participant_scope)->toBe(CamExpensePool::PARTICIPANTS_ALL);

    app(CamReconciliationService::class)->generateAllocations($pool);

    expect($pool->allocations()->count())->toBe(2)
        ->and(round((float) $pool->allocations()->sum('allocated_amount'), 2))->toBe(100000.0);
});

it('lets a property run two pools in the same year', function () {
    // The key widened from (asset, year) to (asset, year, pool_code); before this the second
    // create was refused outright.
    $asset = makeAsset();

    poolFor($asset, ['pool_code' => 'cam']);
    $tax = poolFor($asset, ['pool_code' => 'tax', 'name' => 'Real estate tax']);

    expect(CamExpensePool::where('asset_id', $asset->id)->where('period_year', 2026)->count())->toBe(2)
        ->and($tax->label())->toBe('Real estate tax');
});

it('still refuses two pools with the SAME code in a year', function () {
    // Widening the key must not lose it: two `cam` pools for 2026 would double-recover the year.
    $asset = makeAsset();
    poolFor($asset, ['pool_code' => 'cam']);

    expect(fn () => poolFor($asset, ['pool_code' => 'cam']))
        ->toThrow(QueryException::class);
});

it('charges a zone pool only to the leases in that zone', function () {
    // THE story, in Yardi's own words: everyone shares CAM, but only the food court shares
    // grease-trap cleaning.
    $asset = makeAsset();
    $foodCourt = Area::create(['asset_id' => $asset->id, 'name' => 'Food court', 'code' => 'FC']);

    $diner = activeLeaseIn($asset, 'FC-1', 100, $foodCourt);
    $otherDiner = activeLeaseIn($asset, 'FC-2', 100, $foodCourt);
    $bookshop = activeLeaseIn($asset, 'RT-1', 200);          // not in the zone

    $pool = poolFor($asset, [
        'pool_code' => 'grease',
        'name' => 'Grease-trap cleaning',
        'participant_scope' => CamExpensePool::PARTICIPANTS_AREA,
        'participant_area_id' => $foodCourt->id,
        'total_actual_expense' => 60000,
    ]);

    app(CamReconciliationService::class)->generateAllocations($pool);

    $allocated = $pool->allocations()->pluck('allocated_amount', 'lease_id');

    expect($allocated)->toHaveCount(2)
        ->and($allocated->has($bookshop->id))->toBeFalse()
        // Split between the two zone tenants on their own area, NOT diluted by the bookshop's.
        ->and(round((float) $allocated[$diner->id], 2))->toBe(30000.0)
        ->and(round((float) $allocated[$otherDiner->id], 2))->toBe(30000.0);
});

it('divides a zone pool by the ZONE’s leasable area, not the property’s', function () {
    // The trap. On a `gla` basis the property's whole area would spread a food-court pool across the
    // centre and recover a few percent of its own cost — the landlord silently eating the rest.
    $asset = makeAsset(['leasable_area_sqm' => 10000]);
    $foodCourt = Area::create(['asset_id' => $asset->id, 'name' => 'Food court', 'code' => 'FC']);

    $diner = activeLeaseIn($asset, 'FC-1', 100, $foodCourt);
    activeLeaseIn($asset, 'FC-2', 100, $foodCourt);          // in the zone, so in the denominator
    activeLeaseIn($asset, 'RT-1', 9800);                     // the rest of the centre

    $pool = poolFor($asset, [
        'pool_code' => 'grease',
        'participant_scope' => CamExpensePool::PARTICIPANTS_AREA,
        'participant_area_id' => $foodCourt->id,
        'denominator_basis' => CamExpensePool::DENOMINATOR_GLA,
        'total_actual_expense' => 60000,
    ]);

    app(CamReconciliationService::class)->generateAllocations($pool);

    // 100 / 200 of the zone = half. Against the property's 10,000 it would have been 1%.
    expect(round((float) $pool->allocations()->where('lease_id', $diner->id)->value('allocated_amount'), 2))
        ->toBe(30000.0);
});

it('includes a multi-unit lease whose annexe is in the zone', function () {
    // The pivot trap this project has hit before: clamping to the denormalised master `unit_id`
    // would drop a lease whose master shop is outside the zone but whose annexe is inside it.
    $asset = makeAsset();
    $foodCourt = Area::create(['asset_id' => $asset->id, 'name' => 'Food court', 'code' => 'FC']);

    $lease = activeLeaseIn($asset, 'RT-9', 150);             // master unit: NOT in the zone
    $annexe = makeUnit($asset, ['code' => 'FC-9', 'area_sqm' => 50, 'area_id' => $foodCourt->id]);
    $lease->units()->attach($annexe->id, ['effective_from' => '2026-01-01', 'effective_to' => null]);

    activeLeaseIn($asset, 'FC-1', 200, $foodCourt);

    $pool = poolFor($asset, [
        'pool_code' => 'grease',
        'participant_scope' => CamExpensePool::PARTICIPANTS_AREA,
        'participant_area_id' => $foodCourt->id,
        'total_actual_expense' => 40000,
    ]);

    app(CamReconciliationService::class)->generateAllocations($pool);

    expect($pool->allocations()->where('lease_id', $lease->id)->exists())->toBeTrue();
});

it('ties out separately for each pool', function () {
    // Two pools, two participant sets, two tie-outs. If they shared anything the sums would not
    // both land, and CAM's invariant is that Σ allocated + landlord_unrecovered = the pool total.
    $asset = makeAsset();
    $foodCourt = Area::create(['asset_id' => $asset->id, 'name' => 'Food court', 'code' => 'FC']);

    activeLeaseIn($asset, 'FC-1', 100, $foodCourt);
    activeLeaseIn($asset, 'RT-1', 300);

    $cam = poolFor($asset, ['pool_code' => 'cam', 'total_actual_expense' => 80000]);
    $grease = poolFor($asset, [
        'pool_code' => 'grease',
        'participant_scope' => CamExpensePool::PARTICIPANTS_AREA,
        'participant_area_id' => $foodCourt->id,
        'total_actual_expense' => 20000,
    ]);

    $service = app(CamReconciliationService::class);
    $service->generateAllocations($cam);
    $service->generateAllocations($grease);

    $ties = fn (CamExpensePool $p) => round(
        (float) $p->allocations()->sum('allocated_amount') + (float) $p->fresh()->landlord_unrecovered_amount, 2
    );

    expect($ties($cam))->toBe(80000.0)
        ->and($ties($grease))->toBe(20000.0)
        ->and($grease->allocations()->count())->toBe(1);
});
