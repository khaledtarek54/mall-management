<?php

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Services\CamReconciliationService;
use Carbon\CarbonImmutable;

/**
 * Regression: AN ANCHOR MAY BE CARVED OUT OF THE DENOMINATOR — Yardi's *adjusted* basis.
 *
 * Yardi offers four denominators and Atriom shipped three: total GLA (the landlord eats the
 * vacancy), occupied area (the sitting tenants do), and a fixed stated figure. The fourth is
 * **adjusted** — "anchors carved out, in-line tenants share the rest" — recorded in the benchmark as
 * having no equivalent here.
 *
 * It is the anchor deal every mall signs, and `stated_share_pct` alone does not express it. An
 * anchor of 3,000 m² negotiating 5% leaves its floor area sitting in the divisor, diluting every
 * in-line tenant, so the pool under-recovers by most of its value and the landlord absorbs the
 * difference with nothing on any screen saying why. Carved out, the anchor takes the 5% its
 * contract names and the in-line tenants divide the remaining 95% over their OWN area.
 *
 * The remainder split is the half that is easy to miss: dividing the WHOLE pool over the reduced
 * denominator would recover 100% from the in-line tenants AND 5% from the anchor — an over-recovery
 * the pool's own guard would then refuse.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

beforeEach(function () {
    CarbonImmutable::setTestNow('2028-01-15');
    $this->asset = makeAsset(['leasable_area_sqm' => 4000]);
    $span = ['commencement_date' => '2024-01-01', 'expiry_date' => '2030-12-31'];

    // An anchor of 3,000 m² beside two 500 m² in-line shops.
    $this->anchor = makeLease(makeUnit($this->asset, ['area_sqm' => 3000]), makeTenant(), $span)->fresh();
    $this->a = makeLease(makeUnit($this->asset, ['area_sqm' => 500]), makeTenant(), $span)->fresh();
    $this->b = makeLease(makeUnit($this->asset, ['area_sqm' => 500]), makeTenant(), $span)->fresh();

    $this->pool = fn () => CamExpensePool::create([
        'asset_id' => $this->asset->id, 'period_year' => 2027,
        'pool_code' => CamExpensePool::CODE_CAM, 'status' => 'draft',
        'total_actual_expense' => 100_000, 'total_estimated_collected' => 0,
        'expense_basis' => 'stated', 'estimate_basis' => 'stated', 'admin_fee_pct' => 0,
    ]);

    $this->run = function (CamExpensePool $pool): array {
        app(CamReconciliationService::class)->generateAllocations($pool);

        return CamAllocation::where('cam_expense_pool_id', $pool->id)
            ->get()->mapWithKeys(fn ($x) => [$x->lease_id => (float) $x->allocated_amount])->all();
    };
});

it('carves the anchor out and lets the in-line tenants share the rest', function () {
    $this->anchor->camTerms()->create([
        'effective_year' => 2027, 'stated_share_pct' => 5, 'excluded_from_denominator' => true,
    ]);

    $pool = ($this->pool)();
    $allocated = ($this->run)($pool);

    // Anchor takes its contracted 5%. The other 95% divides over the IN-LINE area (1,000 m²), so
    // each 500 m² shop takes half of it.
    expect($allocated[$this->anchor->id])->toBe(5_000.0)
        ->and($allocated[$this->a->id])->toBe(47_500.0)
        ->and($allocated[$this->b->id])->toBe(47_500.0);

    // And the pool is recovered in FULL — which is the whole point: without the carve-out the
    // landlord silently bore most of it.
    $pool->refresh();
    expect(round((float) $pool->allocations()->sum('allocated_amount'), 2))->toBe(100_000.0)
        ->and(round((float) $pool->landlord_unrecovered_amount, 2))->toBe(0.0);
});

it('shows what the same deal costs the landlord WITHOUT the carve-out', function () {
    // The control that makes the feature's value measurable rather than asserted. Same 5% anchor,
    // left in the divisor: its 3,000 m² dilutes everyone and 87,500 goes unrecovered.
    $this->anchor->camTerms()->create([
        'effective_year' => 2027, 'stated_share_pct' => 5, 'excluded_from_denominator' => false,
    ]);

    $pool = ($this->pool)();
    $allocated = ($this->run)($pool);

    expect($allocated[$this->anchor->id])->toBe(5_000.0)
        // 500 / 4,000 = 12.5% each — diluted by an anchor that is not paying on that basis.
        ->and($allocated[$this->a->id])->toBe(12_500.0)
        ->and($allocated[$this->b->id])->toBe(12_500.0)
        ->and(round((float) $pool->fresh()->landlord_unrecovered_amount, 2))->toBe(70_000.0);
});

it('refuses a carve-out whose contract names no share', function () {
    // A lease outside the divisor has no area basis left to derive a share from. Allocating it zero
    // would read as a tenant who pays nothing, which is a wrong number rather than a refusal.
    $this->anchor->camTerms()->create([
        'effective_year' => 2027, 'excluded_from_denominator' => true, // no stated share
    ]);

    // ASSERT THE MESSAGE, not just the class. Without a stated share the anchor's 3,000 m² divides
    // by a 1,000 m² in-line denominator for a 300% share, so the OVER-RECOVERY guard throws a
    // `DomainException` too — and a bare `toThrow(DomainException::class)` passed with this guard
    // deleted, proving the other one. (Mutation caught exactly that.)
    expect(fn () => ($this->run)(($this->pool)()))
        ->toThrow(DomainException::class, __('admin.refusals.cam_carve_out_needs_a_stated_share', [
            'lease' => $this->anchor->reference,
        ]));
});

it('changes nothing when nobody is carved out', function () {
    // The parity case: with no carve-out the shares are the plain area split they always were.
    $allocated = ($this->run)(($this->pool)());

    expect($allocated[$this->anchor->id])->toBe(75_000.0)
        ->and($allocated[$this->a->id])->toBe(12_500.0)
        ->and($allocated[$this->b->id])->toBe(12_500.0);
});

it('resolves the carve-out per POOL, like every other clause on the term', function () {
    // An anchor carved out of CAM is an ordinary participant in the food-court pool it also trades
    // in — the deal was struck about one pool.
    $this->anchor->camTerms()->create([
        'effective_year' => 2027, 'pool_code' => CamExpensePool::CODE_CAM,
        'stated_share_pct' => 5, 'excluded_from_denominator' => true,
    ]);

    expect(($this->run)(($this->pool)())[$this->anchor->id])->toBe(5_000.0);

    $grease = CamExpensePool::create([
        'asset_id' => $this->asset->id, 'period_year' => 2027, 'pool_code' => 'fc_grease',
        'status' => 'draft', 'total_actual_expense' => 100_000, 'total_estimated_collected' => 0,
        'expense_basis' => 'stated', 'estimate_basis' => 'stated', 'admin_fee_pct' => 0,
    ]);

    // 3,000 of 4,000 — the plain area share, because the CAM clause says nothing about this pool.
    expect(($this->run)($grease)[$this->anchor->id])->toBe(75_000.0);
});
