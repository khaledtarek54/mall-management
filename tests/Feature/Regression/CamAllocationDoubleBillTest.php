<?php

/*
|--------------------------------------------------------------------------
| Regression — CAM allocation double-bill
|--------------------------------------------------------------------------
| BUG: CamReconciliationService::generateAllocations() always set
| status='pending', so re-running it on a pool that already had a 'billed'
| allocation reset that allocation back to 'pending'. The next billing pass
| would then bill the same true-up a second time (double-bill).
|
| FIX: generateAllocations() skips any existing allocation whose status is
| not 'pending', leaving 'billed' rows untouched.
|
| This test pins the FIXED behaviour: a 'billed' allocation survives a
| second generateAllocations() call without being reset to 'pending'.
*/

use App\Models\Asset;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Services\CamReconciliationService;

function camDoubleBillService(): CamReconciliationService
{
    return app(CamReconciliationService::class);
}

function makeDoubleBillPool(Asset $asset, array $attrs = []): CamExpensePool
{
    return CamExpensePool::create(array_merge([
        'asset_id' => $asset->id,
        'period_year' => 2026,
        'total_actual_expense' => 100000,
        'total_estimated_collected' => 80000,
        'status' => 'draft',
    ], $attrs));
}

it('does not reset an already-billed allocation back to pending on re-generation', function () {
    $asset = makeAsset();
    makeLease(makeUnit($asset, ['area_sqm' => 100]));

    $pool = makeDoubleBillPool($asset);

    // First pass: a single eligible lease gets one pending allocation.
    expect(camDoubleBillService()->generateAllocations($pool))->toBe(1);

    $alloc = $pool->allocations()->sole();
    expect($alloc->status)->toBe('pending');

    // Mark it billed (as the billing pass would).
    $alloc->update(['status' => 'billed']);
    expect($alloc->fresh()->status)->toBe('billed');

    // Second pass MUST leave the billed allocation alone.
    $count = camDoubleBillService()->generateAllocations($pool);

    // The billed row was skipped — not re-touched/re-counted.
    expect($count)->toBe(0);

    // No duplicate row, and status stays 'billed' (NOT reset to 'pending').
    expect(CamAllocation::where('cam_expense_pool_id', $pool->id)->count())->toBe(1);
    expect($alloc->fresh()->status)->toBe('billed');
    expect($pool->allocations()->sole()->status)->toBe('billed');
});
