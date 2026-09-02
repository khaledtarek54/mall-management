<?php

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Services\CamReconciliationService;
use Carbon\CarbonImmutable;

/**
 * A BILLED ALLOCATION IS STILL MONEY THE POOL RECOVERED.
 *
 * `generateAllocations()` skips an allocation that is no longer `pending` — correct, and the whole
 * point of the freeze: that row is evidence, the tenant was invoiced from it, and re-deriving it
 * against a denominator that has since moved is exactly what `basisFrozen()` exists to prevent.
 *
 * But it skipped the row out of the **SUM** as well as out of the write. `$allocatedTotal` feeds
 * `landlord_unrecovered_amount = total_actual_expense − $allocatedTotal`, so every already-billed
 * share was reported as money the landlord bore itself.
 *
 * A re-run exists precisely to push a revised expense through a pool that has already billed, so
 * this is the ordinary path, not an edge: the landlord's own P&L reads a common cost it never
 * carried, and `Σ allocated + unrecovered = total` — the identity `billing:reconcile` checks, and
 * therefore `atriom:preflight` — fails by the whole billed total on a pool where nothing is wrong.
 *
 * The billed row contributes its OWN stored figure, never a recomputed one, for the same reason it
 * is not rewritten.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

beforeEach(function () {
    CarbonImmutable::setTestNow('2029-01-15');

    $this->asset = makeAsset(['leasable_area_sqm' => 200]);
    $span = ['status' => 'active', 'commencement_date' => '2027-01-01', 'expiry_date' => '2032-12-31'];

    $this->a = makeLease(makeUnit($this->asset, ['area_sqm' => 100]), null, $span)->fresh();
    $this->b = makeLease(makeUnit($this->asset, ['area_sqm' => 100]), null, $span)->fresh();

    $this->pool = CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'period_year' => 2028,
        'status' => 'draft',
        'denominator_basis' => CamExpensePool::DENOMINATOR_OCCUPIED,
        'total_actual_expense' => 100_000,
        'total_estimated_collected' => 0,
    ]);

    $this->svc = app(CamReconciliationService::class);
});

/** `Σ allocated + unrecovered`, which must equal the pool's actual expense. */
function poolTieOut(CamExpensePool $pool): float
{
    $pool = $pool->fresh();

    return round(
        (float) $pool->allocations()->sum('allocated_amount')
        + (float) $pool->landlord_unrecovered_amount,
        2,
    );
}

it('keeps the tie-out when a re-run meets an allocation that is already billed', function () {
    $this->svc->generateAllocations($this->pool);

    // The control: the first run ties out, and both leases carry half.
    expect(poolTieOut($this->pool))->toBe(100_000.0)
        ->and((float) $this->pool->fresh()->landlord_unrecovered_amount)->toBe(0.0);

    // One tenant's share is billed — the freeze this loop respects.
    CamAllocation::query()
        ->where('cam_expense_pool_id', $this->pool->id)
        ->where('lease_id', $this->a->id)
        ->update(['status' => 'billed']);

    // The operator revises the expense and re-runs, which is what a re-run is for.
    $this->svc->generateAllocations($this->pool->fresh());

    $pool = $this->pool->fresh();

    expect(poolTieOut($pool))->toBe(100_000.0)
        // …and the landlord is not told it bore the 50,000 a tenant was invoiced for.
        ->and((float) $pool->landlord_unrecovered_amount)->toBe(0.0);
});

it('leaves the billed allocation itself untouched', function () {
    // Counting it must not become re-deriving it: that row is evidence and the tenant was invoiced
    // from it. A fix that simply stopped skipping would satisfy the tie-out above and rewrite a
    // document.
    $this->svc->generateAllocations($this->pool);

    $billed = CamAllocation::query()
        ->where('cam_expense_pool_id', $this->pool->id)
        ->where('lease_id', $this->a->id)
        ->sole();

    $billed->update(['status' => 'billed', 'allocated_amount' => 49_000]);

    $this->svc->generateAllocations($this->pool->fresh());

    expect((float) $billed->fresh()->allocated_amount)->toBe(49_000.0)
        ->and($billed->fresh()->status)->toBe('billed')
        // …and the unrecovered figure follows the row's OWN stored number, not a recomputed one.
        ->and((float) $this->pool->fresh()->landlord_unrecovered_amount)->toBe(1_000.0);
});

it('never deletes a billed allocation, even when its lease has stopped qualifying', function () {
    // The property, not the mechanism. Today it holds because the stale-row cleanup is guarded on
    // `! $isRerun` and a billed row is what MAKES it a re-run — so marking the row present in
    // `$touched` turns no test red on its own, which is stated in the service rather than dressed up
    // as a proof here. What is worth pinning either way is the outcome: the document a tenant was
    // invoiced from survives a re-run in which the lease behind it has left the pool.
    $this->svc->generateAllocations($this->pool);

    CamAllocation::query()
        ->where('cam_expense_pool_id', $this->pool->id)
        ->where('lease_id', $this->a->id)
        ->update(['status' => 'billed']);

    // The lease stops qualifying — its term ends before the pool's year.
    $this->a->update(['expiry_date' => '2027-06-30']);

    $this->svc->generateAllocations($this->pool->fresh());

    expect(CamAllocation::query()
        ->where('cam_expense_pool_id', $this->pool->id)
        ->where('lease_id', $this->a->id)
        ->exists())->toBeTrue();
});
