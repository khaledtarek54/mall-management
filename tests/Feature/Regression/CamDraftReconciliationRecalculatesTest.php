<?php

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Services\CamReconciliationService;
use Carbon\CarbonImmutable;

/**
 * Regression: WHAT FREEZES A RECONCILIATION IS BILLING IT, NOT CALCULATING IT.
 *
 * `generateAllocations()` froze the participant set, the shares and the denominator as soon as ANY
 * allocation existed — so a pool that had merely been calculated could never be recalculated. The
 * other two layers drew the line in the other place: `CamExpensePoolForm::basisFrozen()` disables
 * the basis fields only once an allocation is NOT PENDING, and the one test asserting the
 * denominator freeze says "on a pool with BILLED allocations" in its own comment while billing
 * nothing. Three layers, three different definitions of frozen.
 *
 * Measured on the demo books (2026-08-31): an operator generated a 2027 pool, switched
 * `denominator_basis` from `occupied` to `gla`, saved — the column really changed — regenerated (36
 * allocations, success toast), and every share, the denominator and the landlord's figure were
 * byte-identical. The screen said GLA and the arithmetic said occupied, with nothing reporting it.
 *
 * And there was no way back. `void` refuses a PENDING allocation (it un-bills, and nothing was
 * billed), no screen deletes one, and `CamExpensePool` is
 * `#[DeletableWhenUnused(blockedBy: ['allocations'])]` — so the pool could not be deleted either.
 * A wrong denominator on the first run was unrecoverable from the panel.
 *
 * YARDI is the standard and it is unambiguous: Recovery Reconciliation is a BATCH you review before
 * you post — "review estimated vs actual expenses, tenant share calculations, and generate
 * reconciliation statements" — and an unposted batch recalculates freely. Posting is the freeze.
 * `billed` is this system's posting; `pending` is the draft.
 *
 * The genuine hazard the old guard protected against is real and is NOT relaxed: with some
 * allocations billed, recomputing the pending ones against a new denominator breaks
 * `Σ allocated = total_actual_expense`. `CamDenominatorTest` and `CamClauseReviewHardeningTest`
 * both pin that, and both now bill first — which is the state their own comments describe.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

beforeEach(function () {
    CarbonImmutable::setTestNow('2029-01-15');

    $this->twoEqualLeases = function (float $gla = 1000) {
        $asset = makeAsset(['leasable_area_sqm' => $gla]);
        $span = ['status' => 'active', 'commencement_date' => '2027-01-01', 'expiry_date' => '2032-12-31'];

        return [
            $asset,
            makeLease(makeUnit($asset, ['area_sqm' => 100]), null, $span)->fresh(),
            makeLease(makeUnit($asset, ['area_sqm' => 100]), null, $span)->fresh(),
        ];
    };

    $this->pool = fn (int $assetId, array $extra = []) => CamExpensePool::create(array_merge([
        'asset_id' => $assetId,
        'period_year' => 2028,
        'status' => 'draft',
        'total_actual_expense' => 100_000,
        'total_estimated_collected' => 0,
    ], $extra));

    $this->shareOf = fn (CamExpensePool $pool, $lease) => (float) CamAllocation::query()
        ->where('cam_expense_pool_id', $pool->id)->where('lease_id', $lease->id)->sole()->pro_rata_share_pct;
});

it('re-cuts the shares when the denominator changes and nothing has been billed', function () {
    [$asset, $a] = ($this->twoEqualLeases)(1000);

    $pool = ($this->pool)($asset->id, ['denominator_basis' => CamExpensePool::DENOMINATOR_OCCUPIED]);
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);

    // 100 m² of the 200 m² trading.
    expect(($this->shareOf)($pool, $a))->toBe(50.0);

    $pool->update(['denominator_basis' => CamExpensePool::DENOMINATOR_GLA]);
    $svc->generateAllocations($pool->fresh());

    expect(($this->shareOf)($pool, $a))->toBe(10.0)             // 100 of the 1,000 m² GLA
        // The stored denominator moves too — it is the column the screen would otherwise contradict.
        ->and((float) $pool->fresh()->denominator_used_sqm)->toBe(1000.0)
        // And the tie-out holds: what no share reached is now the landlord's vacancy.
        ->and(round(
            (float) $pool->allocations()->sum('allocated_amount') + (float) $pool->fresh()->landlord_unrecovered_amount, 2
        ))->toBe(100_000.0);
});

it('removes a pending allocation whose participant has left the set, rather than stranding it', function () {
    // The hazard this change INTRODUCES, and the reason the cleanup exists. The loop only creates
    // and updates; while the set was pinned that was complete by construction. Now that an unbilled
    // pool re-resolves its participants, a lease that has stopped qualifying would keep a stale
    // pending row carrying its old share — money allocated to somebody the pool no longer includes,
    // which breaks Σ allocated + unrecovered = total in the direction the tie-out reads as drift.
    [$asset, $a, $b] = ($this->twoEqualLeases)();

    $pool = ($this->pool)($asset->id);
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);

    expect($pool->allocations()->count())->toBe(2)
        ->and(($this->shareOf)($pool, $a))->toBe(50.0);

    // The operator cancels a lease raised in error. `participants()` excludes it outright.
    $b->update(['status' => 'cancelled']);
    $svc->generateAllocations($pool->fresh());

    expect($pool->allocations()->count())->toBe(1)
        ->and(CamAllocation::where('cam_expense_pool_id', $pool->id)->where('lease_id', $b->id)->exists())->toBeFalse()
        ->and(($this->shareOf)($pool, $a))->toBe(100.0)
        ->and(round(
            (float) $pool->allocations()->sum('allocated_amount') + (float) $pool->fresh()->landlord_unrecovered_amount, 2
        ))->toBe(100_000.0);
});

it('never deletes a departed participant whose allocation was already billed', function () {
    // The control on the cleanup. A committed allocation is evidence — it is what a tenant was
    // invoiced from — and is deleted by nothing. Once anything is billed the set is pinned again,
    // which is what keeps it out of reach.
    [$asset, $a, $b] = ($this->twoEqualLeases)();

    $pool = ($this->pool)($asset->id);
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $svc->bill(CamAllocation::where('cam_expense_pool_id', $pool->id)->where('lease_id', $b->id)->sole());

    $b->update(['status' => 'cancelled']);
    $svc->generateAllocations($pool->fresh());

    expect($pool->allocations()->count())->toBe(2)
        ->and(CamAllocation::where('cam_expense_pool_id', $pool->id)->where('lease_id', $b->id)->sole()->status)->toBe('billed')
        // And A stays frozen at its established share, because the pool is posted.
        ->and(($this->shareOf)($pool, $a))->toBe(50.0);
});
