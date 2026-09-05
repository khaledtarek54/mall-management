<?php

use App\Enums\UnitOwnershipStatus;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\UnitOwnership;
use App\Services\CamReconciliationService;
use App\Support\DeletionPolicy;
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
 * **What triggers it is pressing *Generate allocations* again** — `CamExpensePoolActions::canGenerate()`
 * keeps that button live in `reconciling`, which is exactly the status a partly-billed pool sits in
 * — and the scheduled `cam:reconcile` sweep, which regenerates every `draft|reconciling` pool of the
 * year unattended, so the wrong figure was re-applied on a timer. (It is NOT a revised expense: the
 * pool's own `updating` guard refuses that outright while anything is billed. The first version of
 * this file said otherwise, inheriting a claim from a comment in the service that named a test which
 * bills nothing.)
 *
 * The landlord's own P&L then reads a common cost it never carried, and `Σ allocated + unrecovered =
 * total` — the identity `billing:reconcile` checks, and therefore `atriom:preflight` — fails by the
 * whole billed total on a pool where nothing is wrong.
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

it('ties out when a participant has been deleted out from under its allocation', function () {
    // **The same corruption, the exit nobody had closed.** The accumulator only ever saw the
    // participants a pass visited, so a row whose participant is GONE was reported as money the
    // landlord bore — and a `UnitOwnership` could be deleted with a pending allocation against it,
    // because `camAllocations` was missing from its `blockedBy` where `Lease` has always had it.
    //
    // Deriving the figure from the pool's OWN rows closes it, the billed exit and the zero-share
    // exit in one move, which is the point: three bugs with one symptom, and patching them one at a
    // time is how the second and third survived the first.
    $owner = makeTenant();
    $ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => makeUnit($this->asset, ['area_sqm' => 100])->id,
        'tenant_id' => $owner->id,
        'tenure_type' => 'freehold',
        'status' => UnitOwnershipStatus::HandedOver,
        'assessment_basis' => 'area',
        'ownership_share_pct' => 100,
        'started_at' => '2027-01-01',
        'handover_date' => '2027-01-01',
        'currency' => 'EGP',
    ]);

    $this->svc->generateAllocations($this->pool);

    expect(poolTieOut($this->pool))->toBe(100_000.0);

    // Bill one lease so the next pass is a re-run, then delete the owner's row out from under its
    // pending allocation — which the deletion policy now refuses, and used to allow.
    CamAllocation::query()
        ->where('cam_expense_pool_id', $this->pool->id)
        ->where('lease_id', $this->a->id)
        ->update(['status' => 'billed']);

    UnitOwnership::withoutEvents(fn () => $ownership->delete());

    $this->svc->generateAllocations($this->pool->fresh());

    // The orphan row is still in the pool, so it is still recovered money — and it can never be
    // cleaned up either, because the stale-row sweep is gated on `! $isRerun`.
    expect(poolTieOut($this->pool))->toBe(100_000.0);
});

it('refuses to delete an ownership that carries an allocation at all', function () {
    // The other half, and the one that stops the orphan existing. `Lease` has listed
    // `camAllocations` in its own `blockedBy` from the beginning; an ownership did not.
    $owner = makeTenant();
    $ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => makeUnit($this->asset, ['area_sqm' => 100])->id,
        'tenant_id' => $owner->id,
        'tenure_type' => 'freehold',
        'status' => UnitOwnershipStatus::HandedOver,
        'assessment_basis' => 'area',
        'ownership_share_pct' => 100,
        'started_at' => '2027-01-01',
        'handover_date' => '2027-01-01',
        'currency' => 'EGP',
    ]);

    $this->svc->generateAllocations($this->pool);

    expect($ownership->fresh()->camAllocations()->exists())->toBeTrue()
        // The registry names the relation, and the gate separately proves the relation EXISTS — a
        // typo'd one blocks nothing and looks identical to a working guard.
        ->and(DeletionPolicy::blockingRelationsFor(UnitOwnership::class))
        ->toContain('camAllocations');
});
