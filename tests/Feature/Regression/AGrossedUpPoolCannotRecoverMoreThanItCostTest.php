<?php

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Services\CamReconciliationService;
use Carbon\CarbonImmutable;

/**
 * A GROSSED-UP POOL CANNOT RECOVER MORE THAN IT COST (SW-167).
 *
 * The F-08 guard refuses a reconciliation whose shares together promise away more than the pool —
 * and it asks that question in SHARE space, `Σ share ≤ 100%`, while the allocation multiplies each
 * share by the GROSSED-UP basis. Those are not the same question the moment a gross-up is in force.
 *
 * `apportionmentBasis()` already knew the rule: it refuses to gross up on an `occupied` denominator
 * "because there the shares already sum to 100%". `occupied` was a PROXY for that, and a lease whose
 * contract STATES its percentage fills a `gla` denominator back up without touching the basis.
 *
 * Measured (arithmetic replayed 2026-09-04): a 1,000 m² mall with 600 m² trading, a 1,000,000 pool
 * at 60% variable grossed to a 95% assumption against 60% actual occupancy → basis 1,350,000. Three
 * 200 m² shops take 20% each and one of them states 60%, so Σ shares is exactly 1.000000 and the
 * refusal passes. Σ allocated is then 1,350,000 against 1,000,000 of actual common cost: 350,000
 * billed to tenants for cost nobody incurred, on the tenant-facing recovery invoice, and
 * `landlord_unrecovered_amount` recorded as −350,000 with nothing refusing it.
 *
 * Bounded rather than refused, because what is bounded is not somebody's agreed percentage — it is
 * the LANDLORD'S OWN occupancy assumption, and the clamped figure is stored as `grossed_up_expense`
 * and printed on the tenant's statement, so nothing about it is quiet.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2029-01-15');

    // 600 m² trading out of 1,000 m² leasable — 60% occupancy, which is what makes a gross-up bite.
    $this->asset = makeAsset(['leasable_area_sqm' => 1000]);
    $this->leases = collect(range(1, 3))->map(fn () => makeLease(
        makeUnit($this->asset, ['area_sqm' => 200]),
        makeTenant(),
        ['status' => 'active', 'commencement_date' => '2027-01-01', 'expiry_date' => '2032-12-31'],
    )->fresh());

    $this->pool = fn (): CamExpensePool => CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'period_year' => 2028,
        'status' => 'draft',
        'total_actual_expense' => 1_000_000,
        'total_estimated_collected' => 0,
        'expense_basis' => 'stated',
        'estimate_basis' => 'stated',
        'denominator_basis' => CamExpensePool::DENOMINATOR_GLA,
        'gross_up_pct' => 95,
        'variable_pct' => 60,
        'admin_fee_pct' => 0,
    ]);

    $this->recovered = fn (CamExpensePool $pool): float => round(
        (float) CamAllocation::where('cam_expense_pool_id', $pool->id)->sum('allocated_amount'),
        2,
    );
});

it('does not over-recover when a stated share fills the denominator back up', function (): void {
    // The anchor's own clause: 60% of the pool, where its floor area would give it 20%.
    $this->leases[0]->camTerms()->create(['effective_year' => 2028, 'stated_share_pct' => 60]);

    $pool = ($this->pool)();
    app(CamReconciliationService::class)->generateAllocations($pool);

    expect(($this->recovered)($pool))->toBe(1_000_000.0)
        ->and((float) $pool->fresh()->grossed_up_expense)->toBe(1_000_000.0)
        // Never negative: a negative figure here IS the over-recovery, recorded rather than refused.
        ->and((float) $pool->fresh()->landlord_unrecovered_amount)->toBe(0.0);
});

it('still grosses a partly vacant mall up, because that is the whole point of the assumption', function (): void {
    // The control on the other side. A bound that always bit would satisfy the test above while
    // quietly deleting the feature: 810,000 recovered here is MORE than a plain 60% of the pool.
    $pool = ($this->pool)();
    app(CamReconciliationService::class)->generateAllocations($pool);

    expect((float) $pool->fresh()->grossed_up_expense)->toBe(1_350_000.0)
        ->and(($this->recovered)($pool))->toBe(810_000.0)
        ->and((float) $pool->fresh()->landlord_unrecovered_amount)->toBe(190_000.0);
});

it('still refuses stated shares that together promise away more than the pool', function (): void {
    // F-08 itself, untouched: the bound is not a replacement for the refusal, because 130% of the
    // pool is a contract problem the operator has to look at, not an assumption to scale back.
    $this->leases[0]->camTerms()->create(['effective_year' => 2028, 'stated_share_pct' => 90]);

    expect(fn () => app(CamReconciliationService::class)->generateAllocations(($this->pool)()))
        ->toThrow(DomainException::class);
});

it('bounds the basis by what the shares can absorb, and only then', function (): void {
    // The arithmetic on its own, so a failure says which half moved.
    $pool = ($this->pool)();

    expect($pool->apportionmentBasis(60.0, 0.60))->toBe(1_350_000.0)
        ->and($pool->apportionmentBasis(60.0, 1.0))->toBe(1_000_000.0)
        // An unknown share bounds nothing rather than bounding everything to the bare total.
        ->and($pool->apportionmentBasis(60.0, 0.0))->toBe(1_350_000.0);
});
