<?php

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Lease;
use App\Models\LeaseCamTerm;
use App\Services\CamReconciliationService;
use Carbon\CarbonImmutable;

/**
 * Caps match the clause (phase 6, story RC-07).
 *
 * Two gaps, both of which made the cap the wrong number:
 *
 *   **Scope.** `LeaseCamTerm` capped the tenant's WHOLE share. Most cap clauses cap only the
 *   *controllable* costs and expressly carve out rates, insurance and utilities — a landlord cannot
 *   be asked to absorb a government levy it does not set. Capping everything is more protective
 *   than the contract, so the landlord absorbed money it was entitled to recover.
 *
 *   **Carry-forward.** A cumulative cap banks the headroom of a year that came in under, so a later
 *   spike can draw on it. Without it, running the centre cheaply for three years earns no credit in
 *   the fourth.
 *
 * The safety property is the same as every other story in this phase: `cap_scope` defaults to
 * `total` and `is_controllable` to TRUE, so an existing term caps exactly what it always capped.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function cappedLease(array $termAttrs = [], float $area = 100): array
{
    $asset = makeAsset(['leasable_area_sqm' => 100]);
    $lease = makeLease(makeUnit($asset, ['area_sqm' => $area]), null, [
        'status' => 'active', 'commencement_date' => '2026-01-01', 'expiry_date' => '2032-12-31',
    ]);

    if ($termAttrs !== []) {
        $lease->camTerms()->create(array_merge([
            'effective_year' => 2026,
            'cap_type' => 'absolute',
        ], $termAttrs));
    }

    return [$asset, $lease->fresh()];
}

function cappedPool(int $assetId, int $year, float $expense, array $extra = []): CamExpensePool
{
    return CamExpensePool::create(array_merge([
        'asset_id' => $assetId, 'period_year' => $year, 'status' => 'draft',
        'total_actual_expense' => $expense, 'total_estimated_collected' => 0,
    ], $extra));
}

it('caps the whole share by default, exactly as it always did', function () {
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $lease] = cappedLease(['cap_absolute_amount' => 60000]);

    $pool = cappedPool($asset->id, 2028, 100000);
    app(CamReconciliationService::class)->generateAllocations($pool);

    $a = CamAllocation::where('cam_expense_pool_id', $pool->id)->sole();

    expect((float) $a->capped_cost_amount)->toBe(60000.0)
        ->and((float) $a->cap_absorbed_amount)->toBe(40000.0);
});

it('lets the uncontrollable half pass through above the ceiling', function () {
    // 40% of the pool is rates and insurance — costs the landlord does not set and the clause does
    // not cap. Capping them would have the landlord absorbing a government levy.
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $lease] = cappedLease([
        'cap_absolute_amount' => 30000,
        'cap_scope' => LeaseCamTerm::SCOPE_CONTROLLABLE,
    ]);

    $pool = cappedPool($asset->id, 2028, 100000, ['controllable_pct' => 60]);
    app(CamReconciliationService::class)->generateAllocations($pool);

    $a = CamAllocation::where('cam_expense_pool_id', $pool->id)->sole();

    // Controllable 60,000 capped to 30,000; uncontrollable 40,000 passes through.
    expect((float) $a->capped_cost_amount)->toBe(70000.0)
        ->and((float) $a->cap_absorbed_amount)->toBe(30000.0);
});

it('behaves identically to an unscoped cap when everything is controllable', function () {
    // The bridge between the two scopes, and why `is_controllable` defaults to TRUE: a
    // controllable-scoped cap on an unclassified pool must not silently change anybody's bill.
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $lease] = cappedLease([
        'cap_absolute_amount' => 60000,
        'cap_scope' => LeaseCamTerm::SCOPE_CONTROLLABLE,
    ]);

    $pool = cappedPool($asset->id, 2028, 100000);   // no controllable_pct → 100% controllable
    app(CamReconciliationService::class)->generateAllocations($pool);

    expect((float) CamAllocation::where('cam_expense_pool_id', $pool->id)->sole()->capped_cost_amount)
        ->toBe(60000.0);
});

it('banks the headroom of a year that came in under the ceiling', function () {
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $lease] = cappedLease([
        'cap_absolute_amount' => 100000,
        'cap_carry_forward' => true,
    ]);

    // A cheap year: 70,000 against a 100,000 ceiling banks 30,000.
    $y1 = cappedPool($asset->id, 2027, 70000);
    app(CamReconciliationService::class)->generateAllocations($y1);

    expect((float) CamAllocation::where('cam_expense_pool_id', $y1->id)->sole()->cap_headroom_banked)
        ->toBe(30000.0)
        ->and($lease->fresh()->camCapHeadroomBankedBefore(2028))->toBe(30000.0);
});

it('draws on banked headroom when a later year spikes', function () {
    CarbonImmutable::setTestNow('2030-01-15');
    [$asset, $lease] = cappedLease([
        'cap_absolute_amount' => 100000,
        'cap_carry_forward' => true,
    ]);

    app(CamReconciliationService::class)->generateAllocations(cappedPool($asset->id, 2027, 70000));

    // 150,000 against a 100,000 ceiling + 30,000 banked = 130,000 recoverable.
    $y2 = cappedPool($asset->id, 2028, 150000);
    app(CamReconciliationService::class)->generateAllocations($y2);

    $a = CamAllocation::where('cam_expense_pool_id', $y2->id)->sole();

    expect((float) $a->capped_cost_amount)->toBe(130000.0)
        ->and((float) $a->cap_absorbed_amount)->toBe(20000.0)
        ->and((float) $a->cap_headroom_used)->toBe(30000.0);
});

it('never lets the same headroom be spent twice', function () {
    // The trap. Headroom drawn in 2028 must not still be available in 2029, or a single cheap year
    // would subsidise every spike that follows it.
    CarbonImmutable::setTestNow('2031-01-15');
    [$asset, $lease] = cappedLease([
        'cap_absolute_amount' => 100000,
        'cap_carry_forward' => true,
    ]);

    app(CamReconciliationService::class)->generateAllocations(cappedPool($asset->id, 2027, 70000));   // banks 30,000
    app(CamReconciliationService::class)->generateAllocations(cappedPool($asset->id, 2028, 150000));  // spends 30,000

    expect($lease->fresh()->camCapHeadroomBankedBefore(2029))->toBe(0.0);

    $y3 = cappedPool($asset->id, 2029, 150000);
    app(CamReconciliationService::class)->generateAllocations($y3);

    // Back to the bare ceiling.
    expect((float) CamAllocation::where('cam_expense_pool_id', $y3->id)->sole()->capped_cost_amount)
        ->toBe(100000.0);
});

it('banks nothing when carry-forward is off, however cheap the year', function () {
    // The control, and the default: a term that says nothing about carry-forward does not
    // accumulate, so no existing lease starts banking headroom retrospectively.
    CarbonImmutable::setTestNow('2030-01-15');
    [$asset, $lease] = cappedLease(['cap_absolute_amount' => 100000]);

    app(CamReconciliationService::class)->generateAllocations(cappedPool($asset->id, 2027, 70000));

    $y2 = cappedPool($asset->id, 2028, 150000);
    app(CamReconciliationService::class)->generateAllocations($y2);

    expect((float) CamAllocation::where('cam_expense_pool_id', $y2->id)->sole()->capped_cost_amount)
        ->toBe(100000.0);
});

it('leaves an uncapped lease completely alone', function () {
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $lease] = cappedLease();   // no CAM term at all

    $pool = cappedPool($asset->id, 2028, 100000);
    app(CamReconciliationService::class)->generateAllocations($pool);

    $a = CamAllocation::where('cam_expense_pool_id', $pool->id)->sole();

    expect($a->cap_amount)->toBeNull()
        ->and((float) $a->capped_cost_amount)->toBe(100000.0)
        ->and((float) $a->cap_absorbed_amount)->toBe(0.0)
        ->and((float) $a->cap_headroom_banked)->toBe(0.0);
});
