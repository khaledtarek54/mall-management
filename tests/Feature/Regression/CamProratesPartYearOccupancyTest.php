<?php

/*
|--------------------------------------------------------------------------
| A recovery share follows occupancy, not lease status (2026-08-17)
|--------------------------------------------------------------------------
| Yardi computes a tenant's recovery on the days they occupied WITHIN the recovery period. Atriom
| got this wrong at both ends of a lease, and the two errors pushed money in opposite directions:
|
|   ARRIVING  the area basis weighted on the `lease_unit` pivot window, which is dated only when an
|             expansion or contraction is recorded and is null on an ordinary lease. A lease
|             commencing 1 October drew a FULL year's share — measured on a 500,000 pool, a 100 m²
|             lease three months in took 23.81% / 119,048 against a correct ~6.2% / ~30,883.
|
|   LEAVING   `participants()` filtered on `status = active`, so a tenant who traded January to
|             August and left was not an allocation target at all. The common-area cost of the
|             months they DID occupy was recovered from nobody and fell to the landlord silently.
|
| The older comment in `participants()` had already noticed the asymmetry — "a departed tenant's
| estimate is still part of what the pool collected" — without drawing the conclusion: if their
| estimate counts, so must their share.
|
| The fix narrows the weighting window by the lease's own term and widens the participant set to
| anyone who overlapped the year. Both sides of the fraction move together, so the tie-out is
| unchanged — which is the property the last test here pins.
*/

use App\Models\CamExpensePool;
use App\Services\CamReconciliationService;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['leasable_area_sqm' => 1000]);
    $this->svc = app(CamReconciliationService::class);
});

/** A pool of `$expense` for 2026 on the test property, estimates taken from the stated column. */
function proratePool($ctx, float $expense = 500000): CamExpensePool
{
    return CamExpensePool::create([
        'asset_id' => $ctx->asset->id,
        'period_year' => 2026,
        'pool_code' => 'cam',
        'total_actual_expense' => $expense,
        'total_estimated_collected' => 0,
        'estimate_basis' => CamExpensePool::BASIS_STATED,
        'status' => 'draft',
    ]);
}

/** A lease of `$sqm` running `$from`…`$to`, with `$status`. */
function prorateLease($ctx, float $sqm, string $from, ?string $to, string $status = 'active')
{
    $unit = makeUnit($ctx->asset, ['area_sqm' => $sqm]);

    return makeLease($unit, makeTenant(), array_filter([
        'status' => $status,
        'commencement_date' => $from,
        'expiry_date' => $to,
    ]));
}

/** share% keyed by lease reference. */
function prorateShares(CamExpensePool $pool): array
{
    return $pool->allocations()->with('lease')->get()
        ->mapWithKeys(fn ($a) => [$a->lease->reference => round((float) $a->pro_rata_share_pct, 2)])
        ->all();
}

it('gives a lease commencing mid-year a part-year share, not a whole one', function () {
    $pool = proratePool($this);

    $full = prorateLease($this, 200, '2026-01-01', '2028-12-31');
    // 1 October → 92 of 365 days → 100 m² weighs 25.21 m².
    $late = prorateLease($this, 100, '2026-10-01', '2029-09-30');

    $this->svc->generateAllocations($pool);
    $shares = prorateShares($pool);

    // Before the fix this was 100/300 = 33.33% — a quarter's occupancy at a full year's price.
    expect($shares[$late->reference])->toBe(round(25.21 / 225.21 * 100, 2))
        ->and($shares[$full->reference])->toBe(round(200 / 225.21 * 100, 2));
});

it('keeps a lease that LEFT mid-year in the pool, for the months it occupied', function () {
    $pool = proratePool($this);

    $stayed = prorateLease($this, 200, '2026-01-01', '2028-12-31');
    // Terminated 31 August — termination writes that date onto expiry_date.
    $left = prorateLease($this, 150, '2026-04-01', '2026-08-31', 'terminated');

    $this->svc->generateAllocations($pool);
    $shares = prorateShares($pool);

    // 1 Apr – 31 Aug is 153 days → 150 m² weighs 62.88 m². Before the fix this lease was not an
    // allocation target at all and its months were recovered from nobody.
    expect($shares)->toHaveKey($left->reference)
        ->and($shares[$left->reference])->toBe(round(62.88 / 262.88 * 100, 2))
        ->and($shares[$stayed->reference])->toBe(round(200 / 262.88 * 100, 2));
});

it('leaves a full-year lease exactly where it was', function () {
    $pool = proratePool($this);

    $a = prorateLease($this, 200, '2026-01-01', '2028-12-31');
    $b = prorateLease($this, 300, '2025-06-01', '2028-12-31');

    $this->svc->generateAllocations($pool);
    $shares = prorateShares($pool);

    // The whole point of shipping this safely: pools of full-year leases must not move a piastre.
    expect($shares[$a->reference])->toBe(40.0)
        ->and($shares[$b->reference])->toBe(60.0);
});

it('does not clamp a lease in HOLDOVER, which is still trading past its expiry', function () {
    $pool = proratePool($this);

    prorateLease($this, 200, '2026-01-01', '2028-12-31');
    // Expired on paper in March, still `active` — the holdover case. Clamping it at the expiry date
    // would hand nine months of its common-area cost to nobody.
    $holdover = prorateLease($this, 100, '2024-01-01', '2026-03-31', 'active');

    $this->svc->generateAllocations($pool);

    expect(prorateShares($pool)[$holdover->reference])->toBe(round(100 / 300 * 100, 2));
});

it('excludes a lease that never overlapped the reconciled year', function () {
    $pool = proratePool($this);

    $inside = prorateLease($this, 200, '2026-01-01', '2028-12-31');
    $future = prorateLease($this, 500, '2027-01-01', '2029-12-31');
    $past = prorateLease($this, 400, '2023-01-01', '2025-12-31', 'expired');

    $this->svc->generateAllocations($pool);
    $shares = prorateShares($pool);

    expect($shares)->toHaveKey($inside->reference)
        ->and($shares)->not->toHaveKey($future->reference)
        ->and($shares)->not->toHaveKey($past->reference)
        ->and($shares[$inside->reference])->toBe(100.0);
});

it('still recovers the whole pool and no more — the tie-out both sides of the fraction', function () {
    $pool = proratePool($this, 500000);

    prorateLease($this, 200, '2026-01-01', '2028-12-31');
    prorateLease($this, 120, '2026-01-01', '2028-12-31');
    prorateLease($this, 150, '2026-04-01', '2026-08-31', 'terminated');
    prorateLease($this, 100, '2026-10-01', '2029-09-30');

    $this->svc->generateAllocations($pool);

    $allocated = round((float) $pool->allocations()->sum('allocated_amount'), 2);
    $shares = round((float) $pool->allocations()->sum('pro_rata_share_pct'), 2);

    // Narrowing the numerator without narrowing the denominator would under-recover; this is the
    // assertion that says the fix moved both.
    expect($allocated)->toBe(500000.0)
        ->and($shares)->toBe(100.0);
});
