<?php

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\LeaseCamTerm;
use App\Services\CamReconciliationService;
use App\Services\Reconciliation\BooksReconciliationService;

/**
 * A contractually stated CAM share takes its slice OUT of the pool — it does not widen the pool.
 *
 * **F-08, pre-staging QA 2026-08-19.** A lease whose contract names its percentage takes that
 * percentage (RC-03). The other participants still took their full area share of the *same*
 * denominator, so Σ shares was whatever the arithmetic happened to produce. Measured on four equal
 * 250 m² shops with one stated at 40%:
 *
 *     Q-01 (stated)  40%   400,000
 *     Q-02           25%   250,000
 *     Q-03           25%   250,000
 *     Q-04           25%   250,000
 *     ──────────────────────────────
 *     Σ             115% 1,150,000   against 1,000,000 of actual common cost
 *
 * **Tenants were billed 15% more than the common cost incurred**, on the tenant-facing recovery
 * invoice. Over-recovering a service charge is a commercial and legal exposure — recovery is capped
 * at actual cost in almost every clause.
 *
 * And nothing reported it. The residual is stored as `actual − Σ allocated`, which simply went
 * NEGATIVE, and `billing:reconcile`'s tie-out tests `Σ + residual == actual` — an identity the
 * generator has just made true. (Fairly: that check is not a pure tautology; mutation-tested, it
 * catches an allocation tampered with AFTER generation. What it could not see was an over-recovery
 * the generator itself produced — which is why the last test below exercises the new, independent
 * comparison.)
 *
 * ## What is NOT changed, and why
 *
 * The neighbours keep their own area share. Their leases say "your pro-rata share of the pool", so
 * re-cutting them to cover a discount a third party negotiated would over-bill them against their
 * own terms. A stated share BELOW the area share therefore recovers less of the pool and the
 * landlord bears the difference — deliberate, and pinned by `CamDenominatorTest`
 * ("a stated share does not silently inflate its neighbours").
 *
 * Only the harmful direction is guarded: stated shares that together exceed the pool are REFUSED
 * rather than billed, because there is no way to honour all of them without recovering more than
 * the cost incurred.
 */
beforeEach(function () {
    seedRoles();

    $this->asset = makeAsset();

    $this->leases = collect(['Q-01', 'Q-02', 'Q-03', 'Q-04'])->map(function (string $code) {
        $unit = makeUnit($this->asset, ['code' => $code, 'area_sqm' => 250]);

        // A term that spans every pool year below — `participants()` only counts a lease that was
        // in force during the pool's year.
        return makeLease($unit, null, [
            'status' => 'active',
            'commencement_date' => '2025-01-01',
            'expiry_date' => '2035-12-31',
            'term_months' => 132,
        ]);
    });

    $this->pool = fn (int $year) => CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'name' => "Pool {$year}",
        'period_year' => $year,
        'total_actual_expense' => 1_000_000,
        'total_estimated_collected' => 0,
        'status' => 'draft',
        'estimate_basis' => 'stated',
        'recovery_vat_rate' => 14,
        'admin_fee_pct' => 0,
    ]);

    $this->state = fn ($lease, int $year, float $pct) => LeaseCamTerm::create([
        'lease_id' => $lease->id,
        'effective_year' => $year,
        'stated_share_pct' => $pct,
        // This term states a SHARE and no cap, so cap_type is NULL — the value the column has
        // meant "no ceiling" since it was made nullable. It read 'absolute' here as filler from
        // the days the column was NOT NULL, which `LeaseCamTerm`'s completeness guard now refuses:
        // an absolute cap with no amount resolves to nothing while the lease still shows a cap.
        'cap_type' => null,
        'cap_scope' => LeaseCamTerm::SCOPE_TOTAL,
        'cap_carry_forward' => false,
    ]);
});

it('leaves an ordinary pool with no stated share untouched', function () {
    // The control. The fix must change nothing for the pools that exist today.
    $pool = ($this->pool)(2030);
    app(CamReconciliationService::class)->generateAllocations($pool->fresh());

    $allocations = CamAllocation::where('cam_expense_pool_id', $pool->id)->get();

    expect(round((float) $allocations->sum('pro_rata_share_pct'), 2))->toBe(100.0)
        ->and(round((float) $allocations->sum('allocated_amount'), 2))->toBe(1_000_000.0);
});

it('honours a stated share below the area share and leaves the neighbours alone', function () {
    // The landlord bears the difference. This is the deliberate behaviour, restated here so the
    // over-recovery guard below cannot be mistaken for a licence to re-cut anybody's share.
    $pool = ($this->pool)(2031);
    ($this->state)($this->leases[0], 2031, 10);

    app(CamReconciliationService::class)->generateAllocations($pool->fresh());

    $allocations = CamAllocation::where('cam_expense_pool_id', $pool->id)->get()->keyBy('lease_id');

    expect(round((float) $allocations[$this->leases[0]->id]->pro_rata_share_pct, 2))->toBe(10.0)
        ->and(round((float) $allocations[$this->leases[1]->id]->pro_rata_share_pct, 2))->toBe(25.0)
        // 85% recovered; the remaining 15% stays with the landlord rather than moving to a neighbour.
        ->and(round((float) $allocations->sum('allocated_amount'), 2))->toBe(850_000.0)
        ->and(round((float) $pool->fresh()->landlord_unrecovered_amount, 2))->toBe(150_000.0);
});

it('refuses stated shares that promise away more than the pool holds', function () {
    $pool = ($this->pool)(2032);
    ($this->state)($this->leases[0], 2032, 70);
    ($this->state)($this->leases[1], 2032, 50);

    // Refused rather than clamped: scaling somebody's agreed percentage down is a decision no
    // engine may take on its own, and billing them all in full is the over-recovery being stopped.
    expect(fn () => app(CamReconciliationService::class)->generateAllocations($pool->fresh()))
        ->toThrow(DomainException::class);
});

it('reports an over-recovery independently of the stored residual', function () {
    $pool = ($this->pool)(2033);
    app(CamReconciliationService::class)->generateAllocations($pool->fresh());

    $service = app(BooksReconciliationService::class);
    $camCheck = fn () => collect($service->run(null, false)['checks'])->firstWhere('key', 'cam_allocations');

    expect($camCheck()['passed'])->toBeTrue();

    // Force the state the generator can no longer produce, AND store the residual it would have
    // written — so the old identity check would balance and report nothing. The new comparison
    // reads Σ allocated against the pool expense directly, so it still sees it.
    $allocation = CamAllocation::where('cam_expense_pool_id', $pool->id)->first();
    $allocation->forceFill(['allocated_amount' => (float) $allocation->allocated_amount + 250_000])->saveQuietly();
    $pool->forceFill(['landlord_unrecovered_amount' => -250_000])->saveQuietly();

    expect($camCheck()['passed'])->toBeFalse();
});
