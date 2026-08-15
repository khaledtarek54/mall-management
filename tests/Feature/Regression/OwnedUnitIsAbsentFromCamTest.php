<?php

use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\UnitOwnership;
use App\Services\CamReconciliationService;

/**
 * CHARACTERISATION — what a CAM pool does TODAY when a unit in the mall has been sold.
 *
 * These tests assert the CURRENT behaviour, including the part of it that is wrong. They are written
 * before phase 3 (plan 08 §5.7) so that the change has a measured starting point rather than an
 * argued one, and so a reviewer can see exactly which number moves.
 *
 * The defect in one sentence: **an owned unit occupies common area and pays nothing toward it.**
 * `CamReconciliationService` builds its participants from LEASES. A sold unit has no lease, so it is
 * neither allocated a share nor, on the default basis, counted in the denominator the shares divide
 * by — so the remaining tenants absorb its share of the mall's common cost.
 *
 * Nothing about this is loud. The pool still ties out (Σ allocated = total expense by construction
 * on the occupied basis), every allocation looks correct, and the missing party is missing from a
 * list nobody prints.
 *
 * @see docs/plans/08-unit-owners.md §5.7
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'CAM', 'leasable_area_sqm' => 200]);

    // Two identical 100 m² units. One is LET, one is SOLD.
    $this->letUnit = makeUnit($this->asset, ['area_sqm' => 100]);
    $this->soldUnit = makeUnit($this->asset, ['area_sqm' => 100]);

    $this->lease = makeLease($this->letUnit, null, [
        'commencement_date' => '2025-01-01', 'expiry_date' => '2027-12-31', 'status' => 'active',
    ]);

    UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $this->soldUnit->id,
        'tenant_id' => makeTenant(['party_type' => PartyType::UnitOwner->value])->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2025-01-01',
    ]);

    // A 100,000 pool for a mall that is half let and half sold.
    $this->pool = CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'period_year' => 2026,
        'name' => 'Common area 2026',
        'total_actual_expense' => 100000,
        'total_estimated_collected' => 0,
        'status' => 'draft',
    ]);
});

it('allocates the WHOLE pool to the one tenant, because the sold unit is not a participant', function () {
    app(CamReconciliationService::class)->generateAllocations($this->pool);

    $allocations = CamAllocation::where('cam_expense_pool_id', $this->pool->id)->get();

    // One participant, carrying 100% of a pool for a mall it occupies half of.
    expect($allocations)->toHaveCount(1)
        ->and((float) $allocations->first()->pro_rata_share_pct)->toBe(100.0)
        ->and(round((float) $allocations->first()->allocated_amount, 2))->toBe(100000.00);

    // What SHOULD happen once ownerships participate: 50/50, and the tenant pays 50,000.
    // Recorded here as the target rather than asserted, so this test documents the gap instead of
    // failing for it — phase 3 flips these two expectations and this comment goes with it.
})->group('characterisation');

it('bills that tenant for the owner\'s share of the common cost', function () {
    app(CamReconciliationService::class)->generateAllocations($this->pool);

    $allocation = CamAllocation::where('cam_expense_pool_id', $this->pool->id)->firstOrFail();

    // The tenant's own area is half the mall, so a just share is 50,000. He is charged 100,000 —
    // the sold unit's share of the common area lands on him, and nothing on any screen says so.
    expect(round((float) $allocation->allocated_amount, 2))
        ->toBe(100000.00)
        ->and(round((float) $allocation->allocated_amount, 2))
        ->toBeGreaterThan(50000.00);
})->group('characterisation');

it('ties out perfectly while being wrong, which is why nothing catches it', function () {
    app(CamReconciliationService::class)->generateAllocations($this->pool);

    $allocated = round((float) CamAllocation::where('cam_expense_pool_id', $this->pool->id)->sum('allocated_amount'), 2);

    // Σ allocated == the pool, exactly. The books-check this feeds is satisfied, the pool reconciles,
    // and the reconciliation report shows nothing amiss. A tie-out proves the money was fully
    // apportioned; it cannot notice that it was apportioned over the wrong set of parties.
    expect($allocated)->toBe(100000.00)
        ->and(round((float) $this->pool->fresh()->landlord_unrecovered, 2))->toBe(0.00);
})->group('characterisation');
