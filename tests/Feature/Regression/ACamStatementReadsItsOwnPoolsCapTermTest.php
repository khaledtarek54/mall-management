<?php

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Services\CamReconciliationService;
use App\Services\CamStatementPdfService;
use Carbon\CarbonImmutable;

/**
 * THE CAM STATEMENT READS THE CAP TERM OF THE POOL IT IS FOR (SW-169).
 *
 * `03133a13` made a cap belong to a recovery POOL rather than to a year — `camCapScope()`,
 * `statedCamSharePct()` and their four siblings all take a `?string $poolCode` — and it changed
 * `CamReconciliationService`, the relation manager, the model and the migration. It did not touch
 * `CamStatementPdfService`, whose two calls still pass the year alone, so the DOCUMENT resolves
 * through `camTermFor($year, null)`: the portfolio-wide fallback term, not the one the calculation
 * beside it used.
 *
 * Both figures are the tenant's own audit evidence and both read the opposite of the truth. A
 * `controllable`-scoped cap loses the note explaining that only controllable cost was capped — the
 * very clause that decided the number — and a share the contract NAMED is printed as one derived
 * from floor area, which is a different argument in a dispute.
 *
 * The cap AMOUNT is frozen on the allocation and was always right; only these two are re-derived at
 * render time, which is exactly why nothing caught it.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2028-01-15');

    $this->asset = makeAsset(['leasable_area_sqm' => 100]);
    $this->lease = makeLease(makeUnit($this->asset, ['area_sqm' => 100]), makeTenant(), [
        'commencement_date' => '2024-01-01', 'expiry_date' => '2030-12-31',
    ])->fresh();

    $this->pool = fn (string $code): CamExpensePool => CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'period_year' => 2027,
        'pool_code' => $code,
        'status' => 'draft',
        'total_actual_expense' => 90_000,
        'total_estimated_collected' => 0,
        'expense_basis' => 'stated',
        'estimate_basis' => 'stated',
        'admin_fee_pct' => 0,
    ]);

    $this->factsFor = function (CamExpensePool $pool): array {
        app(CamReconciliationService::class)->generateAllocations($pool);

        return app(CamStatementPdfService::class)->facts(
            CamAllocation::where('cam_expense_pool_id', $pool->id)->sole()
        );
    };
});

it('prints the cap scope of THIS pool, not of the portfolio-wide fallback', function (): void {
    // The lease's general clause caps total cost…
    $this->lease->camTerms()->create([
        'effective_year' => 2027, 'pool_code' => null,
        'cap_type' => 'absolute', 'cap_absolute_amount' => 20_000, 'cap_scope' => 'total',
    ]);
    // …and its food-court clause caps only the CONTROLLABLE part.
    $this->lease->camTerms()->create([
        'effective_year' => 2027, 'pool_code' => 'fc_grease',
        'cap_type' => 'absolute', 'cap_absolute_amount' => 5_000, 'cap_scope' => 'controllable',
    ]);

    $facts = ($this->factsFor)(($this->pool)('fc_grease'));

    expect($facts['cap_scope'])->toBe('controllable')
        // The document must say what the RECONCILIATION did — same term, resolved the same way.
        ->and($facts['cap_scope'])->toBe($this->lease->fresh()->camCapScope(2027, 'fc_grease'))
        // …and the ceiling printed beside it is the food court's, which was never in doubt: it is
        // frozen on the allocation, which is why only the SCOPE read wrong.
        ->and((float) $facts['cap_amount'])->toBe(5_000.0);
});

it('says a share was STATED when THIS pool has one, not when another pool does', function (): void {
    $this->lease->camTerms()->create([
        'effective_year' => 2027, 'pool_code' => null,
        'cap_type' => 'absolute', 'cap_absolute_amount' => 20_000,
    ]);
    $this->lease->camTerms()->create([
        'effective_year' => 2027, 'pool_code' => 'fc_grease', 'stated_share_pct' => 40,
    ]);

    $facts = ($this->factsFor)(($this->pool)('fc_grease'));

    expect($facts['share_is_stated'])->toBeTrue()
        ->and((float) $facts['share_pct'])->toBe(40.0);
});

it('still reads the portfolio-wide term for a pool that has none of its own', function (): void {
    // The control on the other side: passing the pool code must not NARROW a term that was written
    // to govern every pool. `camTermFor()` falls back to the null-pool term, and this is the shape
    // every install that has never written a pool-specific clause has.
    $this->lease->camTerms()->create([
        'effective_year' => 2027, 'pool_code' => null,
        'cap_type' => 'absolute', 'cap_absolute_amount' => 20_000,
        'cap_scope' => 'controllable', 'stated_share_pct' => 25,
    ]);

    $facts = ($this->factsFor)(($this->pool)('cam'));

    expect($facts['cap_scope'])->toBe('controllable')
        ->and($facts['share_is_stated'])->toBeTrue()
        ->and((float) $facts['share_pct'])->toBe(25.0);
});
