<?php

use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Invoice;
use App\Models\UnitOwnership;
use App\Services\CamReconciliationService;

/**
 * A sold unit carries its share of the common cost (plan 08 §5.7, phase 3).
 *
 * These began as CHARACTERISATION tests asserting the defect, so the fix would move a measured
 * number rather than an argued one. Measured before: the single tenant was allocated 100% and billed
 * EGP 100,000 on a mall he occupies half of. They now assert the corrected behaviour, and the
 * before-figures are kept in the comments so a reviewer can see exactly what moved.
 *
 * The defect was: **an owned unit occupies common area and paid nothing toward it.**
 * `CamReconciliationService` builds its participants from LEASES. A sold unit has no lease, so it is
 * neither allocated a share nor, on the default basis, counted in the denominator the shares divide
 * by — so the remaining tenants absorb its share of the mall's common cost.
 *
 * Nothing about this is loud. The pool still ties out (Σ allocated = total expense by construction
 * on the occupied basis), every allocation looks correct, and the missing party is missing from a
 * list nobody prints.
 *
 * @see docs/modules/37-unit-owners.md
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

it('makes the sold unit a participant and splits the pool 50/50', function () {
    app(CamReconciliationService::class)->generateAllocations($this->pool);

    $allocations = CamAllocation::where('cam_expense_pool_id', $this->pool->id)->get();

    // Two participants now — the lease and the ownership — each carrying its own half.
    expect($allocations)->toHaveCount(2)
        ->and($allocations->pluck('pro_rata_share_pct')->map(fn ($p) => (float) $p)->all())->toBe([50.0, 50.0])
        ->and($allocations->pluck('allocated_amount')->map(fn ($a) => round((float) $a, 2))->all())->toBe([50000.00, 50000.00]);

    // Exactly one of the two links is set on every row — never both, never neither.
    expect($allocations->filter(fn ($a) => $a->lease_id !== null)->count())->toBe(1)
        ->and($allocations->filter(fn ($a) => $a->unit_ownership_id !== null)->count())->toBe(1);
});

it('charges the tenant his own half and no longer the owner\'s', function () {
    app(CamReconciliationService::class)->generateAllocations($this->pool);

    $tenantShare = CamAllocation::where('cam_expense_pool_id', $this->pool->id)
        ->whereNotNull('lease_id')->firstOrFail();

    // Measured before the change: 100,000. His own area is half the mall, so a just share is
    // 50,000, and the other half now sits with the owner who uses it.
    expect(round((float) $tenantShare->allocated_amount, 2))->toBe(50000.00);
});

it('still ties out — now over the right set of parties', function () {
    app(CamReconciliationService::class)->generateAllocations($this->pool);

    $allocated = round((float) CamAllocation::where('cam_expense_pool_id', $this->pool->id)->sum('allocated_amount'), 2);

    // The tie-out held BEFORE this change too, which is the whole reason nothing caught the defect:
    // Σ allocated == the pool by construction on the occupied basis, so the books-check was
    // satisfied while the money was apportioned over the wrong set of parties. It still ties out —
    // the point is that it now does so across both participants.
    expect($allocated)->toBe(100000.00)
        ->and(round((float) $this->pool->fresh()->landlord_unrecovered, 2))->toBe(0.00);
});

it('bills the owner his true-up as a real receivable, not just an allocation', function () {
    // The half that makes the fix safe to ship. Allocating a share the system cannot CHARGE would
    // have been a cash regression, not a fix: the tenant correctly drops to 50,000 while the owner's
    // 50,000 sits uncollectable, and the mall recovers half its costs.
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($this->pool);

    $ownerAllocation = CamAllocation::where('cam_expense_pool_id', $this->pool->id)
        ->whereNotNull('unit_ownership_id')->firstOrFail();

    $svc->bill($ownerAllocation);

    $ownership = $ownerAllocation->unitOwnership;
    $invoice = Invoice::where('unit_ownership_id', $ownership->id)
        ->whereHas('items', fn ($q) => $q->where('type', 'cam_recovery'))
        ->firstOrFail();

    expect($ownerAllocation->fresh()->status)->toBe('billed')
        ->and($invoice->tenant_id)->toBe($ownership->tenant_id)
        ->and($invoice->lease_id)->toBeNull()
        ->and($invoice->asset_id)->toBe($this->asset->id)
        ->and(round((float) $invoice->subtotal, 2))->toBe(50000.00);
});
