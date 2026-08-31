<?php

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Services\CamReconciliationService;
use Carbon\CarbonImmutable;

/**
 * Regression: A CAM CAP BELONGS TO A RECOVERY POOL, NOT TO A YEAR.
 *
 * `lease_cam_terms` was `unique(lease_id, effective_year)` and `resolveCamCeiling(int $year)` took a
 * year and nothing else, so EVERY pool reconciling that year applied the SAME ceiling
 * independently — a tenant trading in two pools could bear twice their stated cap. Measured on the
 * demo books: Zööba is in `cam` and `fc_grease` in 2025 under a 45,000 term, and each pool caps at
 * 45,000, so 90,000 is borne against a contract that says 45,000.
 *
 * `camCapHeadroomBankedBefore()` filtered on `period_year <` alone for the same reason, so headroom
 * banked under a grease-trap pool was spendable against the CAM ceiling — indefensible under any
 * reading of a cap clause.
 *
 * Yardi is unambiguous: a property runs several recovery pools "each with a different recovery
 * basis, a different set of participants AND A DIFFERENT CAP". `pool_code` is nullable and null
 * keeps the old meaning — a term naming no pool governs any pool without one of its own — so an
 * install that never writes a pool-specific term reconciles exactly what it always did.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

beforeEach(function () {
    CarbonImmutable::setTestNow('2028-01-15');

    $this->asset = makeAsset(['leasable_area_sqm' => 100]);
    $this->lease = makeLease(makeUnit($this->asset, ['area_sqm' => 100]), makeTenant(), [
        'commencement_date' => '2024-01-01', 'expiry_date' => '2030-12-31',
    ])->fresh();

    // One lease, the whole of each pool — so `allocated` is the pool total and the cap is the only
    // thing that can move the figure.
    $this->pool = fn (string $code, float $actual, int $year = 2027) => CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'period_year' => $year,
        'pool_code' => $code,
        'status' => 'draft',
        'total_actual_expense' => $actual,
        'total_estimated_collected' => 0,
        'expense_basis' => 'stated',
        'estimate_basis' => 'stated',
        'admin_fee_pct' => 0,
    ]);

    $this->borne = function (CamExpensePool $pool) {
        app(CamReconciliationService::class)->generateAllocations($pool);

        return (float) CamAllocation::where('cam_expense_pool_id', $pool->id)
            ->where('lease_id', $this->lease->id)->sole()->capped_cost_amount;
    };
});

it('lets each recovery pool carry its own ceiling', function () {
    $this->lease->camTerms()->create([
        'effective_year' => 2027, 'pool_code' => 'cam',
        'cap_type' => 'absolute', 'cap_absolute_amount' => 20_000,
    ]);
    $this->lease->camTerms()->create([
        'effective_year' => 2027, 'pool_code' => 'fc_grease',
        'cap_type' => 'absolute', 'cap_absolute_amount' => 5_000,
    ]);

    expect(($this->borne)(($this->pool)('cam', 90_000)))->toBe(20_000.0)
        ->and(($this->borne)(($this->pool)('fc_grease', 90_000)))->toBe(5_000.0);
});

it('does not let one year-wide cap be charged once per pool', function () {
    // The defect. A single 20,000 term used to cap EACH pool at 20,000, so the tenant bore 40,000
    // against a contract that says 20,000. It now governs only the pool that has no term of its own.
    $this->lease->camTerms()->create([
        'effective_year' => 2027, 'pool_code' => 'cam',
        'cap_type' => 'absolute', 'cap_absolute_amount' => 20_000,
    ]);

    expect(($this->borne)(($this->pool)('cam', 90_000)))->toBe(20_000.0)
        // The grease pool is NOT capped by the CAM clause — it has no cap of its own, and a cap
        // written for one pool must not silently price another.
        ->and(($this->borne)(($this->pool)('fc_grease', 90_000)))->toBe(90_000.0);
});

it('keeps a term that names no pool governing every pool without one — the legacy meaning', function () {
    // The control that makes the change safe to deploy: every row written before this carries a
    // null pool_code, and must go on behaving exactly as it did.
    $this->lease->camTerms()->create([
        'effective_year' => 2027, 'pool_code' => null,
        'cap_type' => 'absolute', 'cap_absolute_amount' => 20_000,
    ]);

    expect(($this->borne)(($this->pool)('cam', 90_000)))->toBe(20_000.0)
        ->and(($this->borne)(($this->pool)('fc_grease', 90_000)))->toBe(20_000.0);
});

it('prefers a pool-specific term over the catch-all for that pool only', function () {
    $this->lease->camTerms()->create([
        'effective_year' => 2027, 'pool_code' => null,
        'cap_type' => 'absolute', 'cap_absolute_amount' => 20_000,
    ]);
    $this->lease->camTerms()->create([
        'effective_year' => 2027, 'pool_code' => 'fc_grease',
        'cap_type' => 'absolute', 'cap_absolute_amount' => 3_000,
    ]);

    expect(($this->borne)(($this->pool)('cam', 90_000)))->toBe(20_000.0)        // catch-all
        ->and(($this->borne)(($this->pool)('fc_grease', 90_000)))->toBe(3_000.0); // its own
});

it('does not let a later catch-all supersede a pool its own clause governs', function () {
    // Effective dating resolves WITHIN the winning scope. A 2027 portfolio-wide renegotiation must
    // not discard the food-court clause agreed in 2025 — that clause is about a different pool, and
    // reading the two ladders as one would silently retire a term nobody renegotiated.
    $this->lease->camTerms()->create([
        'effective_year' => 2025, 'pool_code' => 'fc_grease',
        'cap_type' => 'absolute', 'cap_absolute_amount' => 3_000,
    ]);
    $this->lease->camTerms()->create([
        'effective_year' => 2027, 'pool_code' => null,
        'cap_type' => 'absolute', 'cap_absolute_amount' => 50_000,
    ]);

    expect(($this->borne)(($this->pool)('fc_grease', 90_000)))->toBe(3_000.0)
        ->and(($this->borne)(($this->pool)('cam', 90_000)))->toBe(50_000.0);
});

it('banks carry-forward headroom within its own pool, never across pools', function () {
    // Grease-trap headroom drawn down against the CAM ceiling is indefensible under any reading.
    $this->lease->camTerms()->create([
        'effective_year' => 2026, 'pool_code' => null, 'cap_carry_forward' => true,
        'cap_type' => 'absolute', 'cap_absolute_amount' => 30_000,
    ]);

    // 2026: the grease pool comes in far under its ceiling and banks 25,000.
    ($this->borne)(($this->pool)('fc_grease', 5_000, 2026));

    $banked = CamAllocation::where('lease_id', $this->lease->id)->sum('cap_headroom_banked');
    expect((float) $banked)->toBe(25_000.0);

    // 2027: the CAM pool must NOT spend it. Ceiling stays 30,000, not 55,000.
    expect(($this->borne)(($this->pool)('cam', 90_000, 2027)))->toBe(30_000.0);

    // And the pool that banked it CAN spend it — otherwise this test would pass on a carry-forward
    // that was simply broken.
    expect(($this->borne)(($this->pool)('fc_grease', 90_000, 2027)))->toBe(55_000.0);
});
