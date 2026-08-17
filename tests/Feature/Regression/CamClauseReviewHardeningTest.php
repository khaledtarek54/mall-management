<?php

use App\Models\CamExpensePool;
use App\Models\LeaseCamTerm;
use App\Services\CamReconciliationService;
use App\Services\RemeasureUnitService;
use Illuminate\Support\Carbon;

/**
 * Regression guards for the three confirmed findings of the adversarial CAM-clause review:
 *   #1 (HIGH)   re-run recomputed the sqm denominator from live unit areas → after partial
 *               billing, pending allocations drifted and Σ(allocated) ≠ total_actual_expense.
 *   #2 (MED)    admin_fee_pct was editable after billing started → inconsistent fees in one pool.
 *   #3/#4/#5    a soft-deleted LeaseCamTerm collided with unique(lease_id, effective_year),
 *               permanently blocking re-creation of that year's cap.
 */
afterEach(fn () => Carbon::setTestNow());

it('freezes the share basis on re-run so a unit-area edit cannot shift the denominator (#1)', function () {
    Carbon::setTestNow('2027-01-15');
    $asset = makeAsset();
    $unitB = makeUnit($asset, ['area_sqm' => 100]);
    $leaseA = makeLease(makeUnit($asset, ['area_sqm' => 100]), makeTenant());
    $leaseB = makeLease($unitB, makeTenant());
    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2026,
        'total_actual_expense' => 10000, 'total_estimated_collected' => 0, 'status' => 'draft',
    ]);
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool); // A = B = 5000 (share 50% each)

    // Bill A only — it freezes at 5000; the pool stays reconciling.
    $svc->bill($pool->allocations()->where('lease_id', $leaseA->id)->sole());

    // Operator corrects B's recorded unit area mid-reconciliation. Dated INTO the pool's year, so
    // the correction genuinely applies to the period being reconciled — otherwise this fixture
    // would prove nothing (a remeasurement dated after the period leaves `areaOn(2026)` alone, and
    // the recompute would find 50% whether or not the basis were frozen).
    // Goes through RemeasureUnitService because that is now the only way an area moves: the column
    // is derived from the dated rows and the model refuses a bare edit (validation sweep, spacing).
    app(RemeasureUnitService::class)->record($unitB, 300, ['effective_from' => '2026-01-01']);

    // Re-run must reuse B's ESTABLISHED 50% share, not recompute 300/400 = 75%.
    $svc->generateAllocations($pool);

    $allocB = $pool->allocations()->where('lease_id', $leaseB->id)->sole();
    expect((float) $allocB->allocated_amount)->toBe(5000.0)               // not 7500
        ->and((float) $allocB->pro_rata_share_pct)->toBe(50.0)            // frozen
        ->and((float) $pool->allocations()->sum('allocated_amount'))->toBe(10000.0); // ties out
});

it('still freezes the share basis when a participant lease is soft-deleted between runs (#1)', function () {
    Carbon::setTestNow('2027-01-15');
    $asset = makeAsset();
    $leaseA = makeLease(makeUnit($asset, ['area_sqm' => 100]), makeTenant());
    $leaseB = makeLease(makeUnit($asset, ['area_sqm' => 100]), makeTenant());
    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2026,
        'total_actual_expense' => 10000, 'total_estimated_collected' => 0, 'status' => 'draft',
    ]);
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $svc->bill($pool->allocations()->where('lease_id', $leaseA->id)->sole());

    trashBypassingDeletionPolicy($leaseB); // soft-delete a participant
    $svc->generateAllocations($pool);

    // A's frozen 5000 is untouched; the pinned set + frozen shares keep the tie-out.
    expect((float) $pool->allocations()->where('lease_id', $leaseA->id)->sole()->allocated_amount)->toBe(5000.0)
        ->and((float) $pool->allocations()->sum('allocated_amount'))->toBe(10000.0);
});

it('refuses to change admin_fee_pct once an allocation is billed (#2)', function () {
    Carbon::setTestNow('2027-01-15');
    $asset = makeAsset();
    makeLease(makeUnit($asset, ['area_sqm' => 100]), makeTenant());
    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2026,
        'total_actual_expense' => 50000, 'total_estimated_collected' => 30000,
        'admin_fee_pct' => 0.10, 'status' => 'draft',
    ]);
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $svc->bill($pool->allocations()->sole());

    expect(fn () => $pool->update(['admin_fee_pct' => 0.05]))
        ->toThrow(DomainException::class);
});

it('allows changing admin_fee_pct while all allocations are still pending (#2)', function () {
    Carbon::setTestNow('2027-01-15');
    $asset = makeAsset();
    makeLease(makeUnit($asset, ['area_sqm' => 100]), makeTenant());
    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2026,
        'total_actual_expense' => 50000, 'total_estimated_collected' => 30000,
        'admin_fee_pct' => 0.10, 'status' => 'draft',
    ]);
    app(CamReconciliationService::class)->generateAllocations($pool); // pending, not billed
    $pool->update(['admin_fee_pct' => 0.05]);
    expect((float) $pool->fresh()->admin_fee_pct)->toBe(0.05);
});

it('lets a cap term be re-created for the same year after deletion (#3/#4/#5)', function () {
    $lease = makeLease(makeUnit(makeAsset(), ['area_sqm' => 100]), makeTenant());
    $term = LeaseCamTerm::create([
        'lease_id' => $lease->id, 'effective_year' => 2026,
        'cap_type' => 'absolute', 'cap_absolute_amount' => 40000,
    ]);
    $term->delete(); // hard delete frees the (lease, 2026) slot

    $replacement = LeaseCamTerm::create([
        'lease_id' => $lease->id, 'effective_year' => 2026,
        'cap_type' => 'absolute', 'cap_absolute_amount' => 35000,
    ]);

    expect(LeaseCamTerm::where('lease_id', $lease->id)->where('effective_year', 2026)->count())->toBe(1)
        ->and((float) $replacement->cap_absolute_amount)->toBe(35000.0);
});
