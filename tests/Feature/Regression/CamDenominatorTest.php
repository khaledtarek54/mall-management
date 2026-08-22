<?php

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Lease;
use App\Services\CamReconciliationService;
use App\Services\Reconciliation\BooksReconciliationService;
use Carbon\CarbonImmutable;

/**
 * The share denominator is a lease term, not a convention (phase 6, story RC-03).
 *
 * It was hard-coded to the summed area of whoever happens to be trading, which recovers 100% of the
 * pool from them — so **a half-empty mall billed its remaining tenants for the whole service
 * charge**. Some leases say exactly that. Many say share of GROSS leasable area, leaving the vacancy
 * with the landlord. A good number simply state the tenant's percentage outright, which no
 * denominator can derive.
 *
 * **The books tie-out is the thing that must not break.** `Σ allocated = total_actual_expense` is a
 * hard check, and it silently assumed full recovery. Under a GLA denominator the shares deliberately
 * sum to less than 100%, so the remainder is now STORED as `landlord_unrecovered_amount` and the
 * check became `Σ allocated + unrecovered = total`. Every basis is verified against the real
 * `BooksReconciliationService` below, not against a re-implementation of it.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function denomAsset(float $gla = 1000): array
{
    $asset = makeAsset(['leasable_area_sqm' => $gla]);

    // 400 m² trading out of 1,000 m² leasable — a 60% vacant mall, which is where the two bases
    // diverge most visibly.
    $a = makeLease(makeUnit($asset, ['area_sqm' => 100]), null, [
        'status' => 'active', 'commencement_date' => '2027-01-01', 'expiry_date' => '2032-12-31',
    ]);
    $b = makeLease(makeUnit($asset, ['area_sqm' => 300]), null, [
        'status' => 'active', 'commencement_date' => '2027-01-01', 'expiry_date' => '2032-12-31',
    ]);

    return [$asset, $a->fresh(), $b->fresh()];
}

function denomPool(int $assetId, string $basis, array $extra = []): CamExpensePool
{
    return CamExpensePool::create(array_merge([
        'asset_id' => $assetId,
        'period_year' => 2028,
        'status' => 'draft',
        'total_actual_expense' => 400000,
        'total_estimated_collected' => 0,
        'denominator_basis' => $basis,
    ], $extra));
}

it('recovers the whole pool from trading tenants on the occupied basis — the legacy behaviour', function () {
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $a, $b] = denomAsset();

    $pool = denomPool($asset->id, CamExpensePool::DENOMINATOR_OCCUPIED);
    app(CamReconciliationService::class)->generateAllocations($pool);

    $allocations = CamAllocation::where('cam_expense_pool_id', $pool->id)->get();

    // 100 and 300 of 400 m².
    expect((float) $allocations->firstWhere('lease_id', $a->id)->pro_rata_share_pct)->toBe(25.0)
        ->and((float) $allocations->firstWhere('lease_id', $b->id)->pro_rata_share_pct)->toBe(75.0)
        ->and(round((float) $allocations->sum('allocated_amount'), 2))->toBe(400000.0)
        // Nothing left with the landlord — which is exactly why this check used to be able to
        // assume full recovery.
        ->and((float) $pool->fresh()->landlord_unrecovered_amount)->toBe(0.0)
        ->and((float) $pool->fresh()->denominator_used_sqm)->toBe(400.0);
});

it('leaves the vacancy with the landlord on the GLA basis, and says how much', function () {
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $a, $b] = denomAsset(1000);

    $pool = denomPool($asset->id, CamExpensePool::DENOMINATOR_GLA);
    app(CamReconciliationService::class)->generateAllocations($pool);

    $allocations = CamAllocation::where('cam_expense_pool_id', $pool->id)->get();

    // 100 and 300 of 1,000 m² — the tenants carry 40% of the pool between them, not 100%.
    expect((float) $allocations->firstWhere('lease_id', $a->id)->pro_rata_share_pct)->toBe(10.0)
        ->and((float) $allocations->firstWhere('lease_id', $b->id)->pro_rata_share_pct)->toBe(30.0)
        ->and(round((float) $allocations->sum('allocated_amount'), 2))->toBe(160000.0)
        // The other 240,000 is the landlord's own vacancy — real money, previously invisible.
        ->and((float) $pool->fresh()->landlord_unrecovered_amount)->toBe(240000.0)
        ->and((float) $pool->fresh()->denominator_used_sqm)->toBe(1000.0);
});

it('keeps the books tie-out green on EVERY basis, through the real reconciliation service', function () {
    // The invariant this story most easily breaks. A GLA pool under the old check read as drift,
    // which would have made the books report cry wolf on every mall with a vacancy — and a check
    // that cries wolf is a check people switch off.
    CarbonImmutable::setTestNow('2029-01-15');

    foreach ([
        CamExpensePool::DENOMINATOR_OCCUPIED,
        CamExpensePool::DENOMINATOR_GLA,
        CamExpensePool::DENOMINATOR_FIXED,
    ] as $basis) {
        [$asset] = denomAsset(1000);
        $pool = denomPool($asset->id, $basis, ['denominator_fixed_sqm' => 800]);
        app(CamReconciliationService::class)->generateAllocations($pool);

        $report = app(BooksReconciliationService::class)->run();
        $camCheck = collect($report['checks'])->firstWhere('key', 'cam_allocations');

        expect($camCheck['passed'])->toBeTrue("basis {$basis} broke the CAM tie-out");
    }
});

it('divides by a contractually fixed area when the pool says so', function () {
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $a, $b] = denomAsset(1000);

    $pool = denomPool($asset->id, CamExpensePool::DENOMINATOR_FIXED, ['denominator_fixed_sqm' => 800]);
    app(CamReconciliationService::class)->generateAllocations($pool);

    // 100 of 800.
    expect((float) CamAllocation::where('cam_expense_pool_id', $pool->id)->where('lease_id', $a->id)->sole()->pro_rata_share_pct)
        ->toBe(12.5)
        ->and((float) $pool->fresh()->denominator_used_sqm)->toBe(800.0);
});

it('falls back to occupied rather than recovering nothing when a fixed denominator is missing', function () {
    // A mis-typed pool should reconcile the way it always did, not silently allocate zero to
    // everyone and leave the whole pool with the landlord.
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $a] = denomAsset(1000);

    $pool = denomPool($asset->id, CamExpensePool::DENOMINATOR_FIXED);   // no fixed sqm set
    app(CamReconciliationService::class)->generateAllocations($pool);

    expect((float) $pool->fresh()->denominator_used_sqm)->toBe(400.0)
        ->and((float) $pool->fresh()->landlord_unrecovered_amount)->toBe(0.0);
});

it('uses the share a lease STATES over any share derived from area', function () {
    // No denominator can produce a percentage the parties simply agreed. Common in Egyptian
    // leases, and previously unrepresentable — the system billed a different number from the
    // contract and nobody could see the difference.
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $a, $b] = denomAsset();

    $a->camTerms()->create([
        'effective_year' => 2028,
        // NULL is "no cap". `LeaseCamTerm::CAP_TYPES` is absolute|yoy|both and the form offers
        // exactly those three — `'none'` was a string only a stray `!== 'none'` visibility check
        // ever mentioned, so this term carried a cap type the reconciliation could not read.
        'cap_type' => null,
        'stated_share_pct' => 5,
    ]);

    $pool = denomPool($asset->id, CamExpensePool::DENOMINATOR_OCCUPIED);
    app(CamReconciliationService::class)->generateAllocations($pool);

    $allocations = CamAllocation::where('cam_expense_pool_id', $pool->id)->get();

    expect((float) $allocations->firstWhere('lease_id', $a->id)->pro_rata_share_pct)->toBe(5.0)
        ->and((float) $allocations->firstWhere('lease_id', $a->id)->allocated_amount)->toBe(20000.0)
        // The OTHER lease is untouched — a stated share does not silently inflate its neighbours,
        // it just means less of the pool is recovered.
        ->and((float) $allocations->firstWhere('lease_id', $b->id)->pro_rata_share_pct)->toBe(75.0)
        // 400,000 − 20,000 − 300,000.
        ->and((float) $pool->fresh()->landlord_unrecovered_amount)->toBe(80000.0);
});

it('freezes the shares on a re-run whatever the basis says today', function () {
    // The hard-won frozen-share guard must survive RC-03: changing the denominator on a pool with
    // billed allocations must not re-cut anybody's share.
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $a] = denomAsset(1000);

    $pool = denomPool($asset->id, CamExpensePool::DENOMINATOR_OCCUPIED);
    app(CamReconciliationService::class)->generateAllocations($pool);

    $before = (float) CamAllocation::where('cam_expense_pool_id', $pool->id)
        ->where('lease_id', $a->id)->sole()->pro_rata_share_pct;

    $pool->update(['denominator_basis' => CamExpensePool::DENOMINATOR_GLA]);
    app(CamReconciliationService::class)->generateAllocations($pool->fresh());

    expect((float) CamAllocation::where('cam_expense_pool_id', $pool->id)->where('lease_id', $a->id)->sole()->pro_rata_share_pct)
        ->toBe($before);
});
