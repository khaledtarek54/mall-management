<?php

/*
|--------------------------------------------------------------------------
| CAM reconciliation — service-level scenarios
|--------------------------------------------------------------------------
| Net-new coverage targeting the lower-level CamReconciliationService API:
|   generateAllocations() (pro-rata by leased sqm) and bill() (true-up Charge).
|
| Already covered elsewhere (NOT redone here):
|   - autoTrueUpForYear() with/without auto-bill, year skipping, empty report,
|     double-run idempotency  → tests/Feature/Services/CamAutoTrueUpTest.php
|   - cam:reconcile command wiring + output  → tests/Feature/Console/ConsoleCommandsTest.php
|   - portal per-tenant allocation scoping   → tests/Feature/Resources/PortalCamAllocationScopingTest.php
*/

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Services\CamReconciliationService;

function camService(): CamReconciliationService
{
    return app(CamReconciliationService::class);
}

function makePool(\App\Models\Asset $asset, array $attrs = []): CamExpensePool
{
    return CamExpensePool::create(array_merge([
        'asset_id' => $asset->id,
        'period_year' => 2026,
        'total_actual_expense' => 100000,
        'total_estimated_collected' => 80000,
        'status' => 'draft',
    ], $attrs));
}

/*
|--------------------------------------------------------------------------
| HAPPY PATH — generate pro-rata allocations from leased area
|--------------------------------------------------------------------------
*/

it('generates one pro-rata allocation per active lease, weighted by leased sqm', function () {
    $asset = makeAsset();
    // areas 100 + 300 = 400 total → 25% / 75%
    $lease1 = makeLease(makeUnit($asset, ['area_sqm' => 100]));
    $lease2 = makeLease(makeUnit($asset, ['area_sqm' => 300]));

    $pool = makePool($asset, ['total_actual_expense' => 100000, 'total_estimated_collected' => 80000]);

    $count = camService()->generateAllocations($pool);

    expect($count)->toBe(2);
    expect($pool->allocations()->count())->toBe(2);

    $a1 = CamAllocation::where('cam_expense_pool_id', $pool->id)->where('lease_id', $lease1->id)->sole();
    $a2 = CamAllocation::where('cam_expense_pool_id', $pool->id)->where('lease_id', $lease2->id)->sole();

    // lease1: 25% share
    expect((float) $a1->pro_rata_share_pct)->toBe(25.0);
    expect((float) $a1->allocated_amount)->toBe(25000.0);
    expect((float) $a1->estimated_paid)->toBe(20000.0);
    expect((float) $a1->true_up_amount)->toBe(5000.0); // under-collected → tenant owes
    expect($a1->status)->toBe('pending');

    // lease2: 75% share
    expect((float) $a2->pro_rata_share_pct)->toBe(75.0);
    expect((float) $a2->allocated_amount)->toBe(75000.0);
    expect((float) $a2->estimated_paid)->toBe(60000.0);
    expect((float) $a2->true_up_amount)->toBe(15000.0);
});

it('produces a negative true-up (credit) when a lease over-paid its estimate', function () {
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset, ['area_sqm' => 100]));

    // single lease takes 100% of the pool; estimated collected > actual expense
    $pool = makePool($asset, ['total_actual_expense' => 80000, 'total_estimated_collected' => 100000]);

    camService()->generateAllocations($pool);

    $alloc = $pool->allocations()->sole();
    expect((float) $alloc->allocated_amount)->toBe(80000.0);
    expect((float) $alloc->estimated_paid)->toBe(100000.0);
    expect((float) $alloc->true_up_amount)->toBe(-20000.0);
    expect($alloc->isOverPaid())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| BOUNDARY — allocation shares sum to the pool total
|--------------------------------------------------------------------------
*/

it('allocated amounts and shares sum to the pool total when areas divide cleanly', function () {
    $asset = makeAsset();
    // 200 + 300 + 500 = 1000 total → 20% / 30% / 50%, all clean against 100000
    makeLease(makeUnit($asset, ['area_sqm' => 200]));
    makeLease(makeUnit($asset, ['area_sqm' => 300]));
    makeLease(makeUnit($asset, ['area_sqm' => 500]));

    $pool = makePool($asset, ['total_actual_expense' => 100000, 'total_estimated_collected' => 80000]);

    camService()->generateAllocations($pool);

    $allocs = $pool->allocations()->get();
    expect($allocs)->toHaveCount(3);

    // Pro-rata shares partition 100% and the pool total exactly.
    expect((float) $allocs->sum('pro_rata_share_pct'))->toBe(100.0);
    expect((float) $allocs->sum('allocated_amount'))->toBe(100000.0);
    expect((float) $allocs->sum('estimated_paid'))->toBe(80000.0);
    expect((float) $allocs->sum('true_up_amount'))->toBe(20000.0);
});

it('leaves only a sub-cent rounding residual on indivisible shares (boundary)', function () {
    $asset = makeAsset();
    // three identical units → 1/3 each; round(100000/3) = 33333.33, sum 99999.99
    makeLease(makeUnit($asset, ['area_sqm' => 100]));
    makeLease(makeUnit($asset, ['area_sqm' => 100]));
    makeLease(makeUnit($asset, ['area_sqm' => 100]));

    $pool = makePool($asset, ['total_actual_expense' => 100000, 'total_estimated_collected' => 0]);

    camService()->generateAllocations($pool);

    $sum = (float) $pool->allocations()->sum('allocated_amount');

    // Each gets 33333.33; the residual is exactly one cent, never more.
    expect($sum)->toBe(99999.99);
    expect(abs(100000.0 - $sum))->toBeLessThanOrEqual(0.01);
});

/*
|--------------------------------------------------------------------------
| NEGATIVE — no eligible leases
|--------------------------------------------------------------------------
*/

it('generates zero allocations for a pool whose asset has no active leases', function () {
    $asset = makeAsset();
    // a draft (non-active) lease must be ignored
    makeLease(makeUnit($asset, ['area_sqm' => 100]), null, ['status' => 'draft']);

    $pool = makePool($asset);

    expect(camService()->generateAllocations($pool))->toBe(0);
    expect($pool->allocations()->count())->toBe(0);
});

it('generates zero allocations when total leased area is zero', function () {
    $asset = makeAsset();
    // active lease, but unit has no area → totalSqm guard returns 0
    makeLease(makeUnit($asset, ['area_sqm' => 0]));

    $pool = makePool($asset);

    expect(camService()->generateAllocations($pool))->toBe(0);
    expect($pool->allocations()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| BILLING — bill() creates a true-up Charge on the lease
|--------------------------------------------------------------------------
*/

it('billing an allocation creates a one-off CAM true-up charge on the lease', function () {
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset, ['area_sqm' => 100]));
    $pool = makePool($asset, ['period_year' => 2026, 'total_actual_expense' => 50000, 'total_estimated_collected' => 40000]);

    camService()->generateAllocations($pool);
    $alloc = $pool->allocations()->sole();
    expect($alloc->status)->toBe('pending');

    $billed = camService()->bill($alloc);

    expect($billed->status)->toBe('billed');
    expect($billed->billed_charge_id)->not->toBeNull();

    $charge = Charge::find($billed->billed_charge_id);
    expect($charge)->not->toBeNull();
    expect($charge->lease_id)->toBe($lease->id);
    expect($charge->type)->toBe('other');
    expect($charge->frequency)->toBe('one_time');
    expect((float) $charge->amount)->toBe(10000.0);          // true_up = 50000 - 40000
    expect((bool) $charge->vat_applicable)->toBeFalse();
    expect($charge->name)->toContain('2026');
    expect($charge->start_date->format('Y-m-d'))->toBe('2026-01-01');
    expect($charge->end_date->format('Y-m-d'))->toBe('2026-12-31');
});

it('billing a negative-true-up allocation issues a credit note (not a negative charge)', function () {
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset, ['area_sqm' => 100]));
    $pool = makePool($asset, ['total_actual_expense' => 30000, 'total_estimated_collected' => 50000]);

    camService()->generateAllocations($pool);
    $billed = camService()->bill($pool->allocations()->sole());

    // The credit is a CreditNote on the tenant's account, not a negative charge
    // (which could be floored away on a negative-total invoice).
    expect($billed->billed_charge_id)->toBeNull()
        ->and($billed->billed_credit_note_id)->not->toBeNull();

    $note = \App\Models\CreditNote::find($billed->billed_credit_note_id);
    expect($note->status)->toBe('issued')
        ->and((float) $note->total)->toBe(20000.0)
        ->and((float) $note->balance)->toBe(20000.0)
        ->and($note->tenant_id)->toBe($lease->tenant_id);

    // No negative charge leaked onto the lease.
    expect(Charge::where('lease_id', $lease->id)->where('amount', '<', 0)->count())->toBe(0);
});

it('re-billing an already-billed allocation is a no-op (no duplicate charge)', function () {
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset, ['area_sqm' => 100]));
    $pool = makePool($asset, ['total_actual_expense' => 50000, 'total_estimated_collected' => 40000]);

    camService()->generateAllocations($pool);
    $alloc = $pool->allocations()->sole();

    $first = camService()->bill($alloc);
    $chargeId = $first->billed_charge_id;

    // second bill on the same (now billed) allocation
    $second = camService()->bill($alloc->fresh());

    expect($second->billed_charge_id)->toBe($chargeId);
    expect(Charge::where('lease_id', $lease->id)->where('type', 'other')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| STATE TRANSITION — pool reconciliation
|--------------------------------------------------------------------------
*/

it('marking a pool reconciled flips status and isReconciled() while preserving allocations', function () {
    $asset = makeAsset();
    makeLease(makeUnit($asset, ['area_sqm' => 100]));
    $pool = makePool($asset, ['status' => 'reconciling']);

    camService()->generateAllocations($pool);
    expect($pool->isReconciled())->toBeFalse();

    $pool->update(['status' => 'reconciled', 'reconciled_at' => now()]);
    $pool->refresh();

    expect($pool->status)->toBe('reconciled');
    expect($pool->isReconciled())->toBeTrue();
    expect($pool->reconciled_at)->not->toBeNull();
    expect($pool->allocations()->count())->toBe(1); // allocations survive the transition
});

it('variance() reports actual-minus-collected for the pool', function () {
    $pool = makePool(makeAsset(), ['total_actual_expense' => 100000, 'total_estimated_collected' => 80000]);
    expect($pool->variance())->toBe(20000.0);
});

/*
|--------------------------------------------------------------------------
| IDEMPOTENCY — re-generating allocations does not duplicate
|--------------------------------------------------------------------------
*/

it('re-generating allocations updates in place instead of duplicating rows', function () {
    $asset = makeAsset();
    $lease1 = makeLease(makeUnit($asset, ['area_sqm' => 100]));
    $lease2 = makeLease(makeUnit($asset, ['area_sqm' => 100]));
    $pool = makePool($asset, ['total_actual_expense' => 100000, 'total_estimated_collected' => 80000]);

    camService()->generateAllocations($pool);
    expect(CamAllocation::where('cam_expense_pool_id', $pool->id)->count())->toBe(2);

    // run a second time — unique(pool,lease) + updateOrCreate must keep it at 2
    $count = camService()->generateAllocations($pool);

    expect($count)->toBe(2);
    expect(CamAllocation::where('cam_expense_pool_id', $pool->id)->count())->toBe(2);
});

it('re-generating after the pool expense changes refreshes the allocation amounts', function () {
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset, ['area_sqm' => 100]));
    $pool = makePool($asset, ['total_actual_expense' => 100000, 'total_estimated_collected' => 80000]);

    camService()->generateAllocations($pool);
    expect((float) $pool->allocations()->sole()->allocated_amount)->toBe(100000.0);

    // expense revised upward, then re-run
    $pool->update(['total_actual_expense' => 120000]);
    camService()->generateAllocations($pool);

    $alloc = $pool->allocations()->sole();
    expect($pool->allocations()->count())->toBe(1);
    expect((float) $alloc->allocated_amount)->toBe(120000.0);
    expect((float) $alloc->true_up_amount)->toBe(40000.0);
});

/*
|--------------------------------------------------------------------------
| SCOPING — allocations stay within the pool's own asset
|--------------------------------------------------------------------------
*/

it('only allocates to leases of the pools own asset, never another assets leases', function () {
    $assetA = makeAsset(['code' => 'AAA']);
    $assetB = makeAsset(['code' => 'BBB']);

    $leaseA = makeLease(makeUnit($assetA, ['area_sqm' => 100]));
    $leaseB = makeLease(makeUnit($assetB, ['area_sqm' => 100])); // different asset

    $pool = makePool($assetA, ['total_actual_expense' => 100000, 'total_estimated_collected' => 0]);

    $count = camService()->generateAllocations($pool);

    expect($count)->toBe(1);
    expect($pool->allocations()->pluck('lease_id')->all())->toBe([$leaseA->id]);
    expect(CamAllocation::where('lease_id', $leaseB->id)->exists())->toBeFalse();

    // since only lease A is in scope, it absorbs 100% of the pool
    expect((float) $pool->allocations()->sole()->pro_rata_share_pct)->toBe(100.0);
    expect((float) $pool->allocations()->sole()->allocated_amount)->toBe(100000.0);
});

it('two pools on different assets bill onto their own leases only', function () {
    $assetA = makeAsset(['code' => 'AAA']);
    $assetB = makeAsset(['code' => 'BBB']);
    $leaseA = makeLease(makeUnit($assetA, ['area_sqm' => 100]));
    $leaseB = makeLease(makeUnit($assetB, ['area_sqm' => 100]));

    $poolA = makePool($assetA, ['total_actual_expense' => 100000, 'total_estimated_collected' => 90000]);
    $poolB = makePool($assetB, ['total_actual_expense' => 50000, 'total_estimated_collected' => 30000]);

    camService()->generateAllocations($poolA);
    camService()->generateAllocations($poolB);

    camService()->bill($poolA->allocations()->sole());
    camService()->bill($poolB->allocations()->sole());

    expect((float) Charge::where('lease_id', $leaseA->id)->where('type', 'other')->sole()->amount)->toBe(10000.0);
    expect((float) Charge::where('lease_id', $leaseB->id)->where('type', 'other')->sole()->amount)->toBe(20000.0);
});
