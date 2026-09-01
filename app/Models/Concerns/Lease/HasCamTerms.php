<?php

namespace App\Models\Concerns\Lease;

use App\Models\CamAllocation;
use App\Models\LeaseCamTerm;
use App\Services\CamReconciliationService;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * **The lease's CAM cap and stated share — everything answered from `lease_cam_terms`.**
 *
 * The most self-contained group in `Lease`: four of the five public methods are one-liners over the
 * private `camTermFor()`, and the trait reaches nothing outside `LeaseCamTerm`, `CamAllocation` and
 * `$this->id`. That is why it moved first — if the extraction pattern is wrong, it is wrong here in
 * the cheapest possible place.
 *
 * `camCapHeadroomBankedBefore()` queries `CamAllocation` directly rather than through the model's
 * `camAllocations()` relation, which is why that relation stays on `Lease` — the two are not
 * coupled, despite the shared word.
 *
 * @see CamReconciliationService  the only real consumer
 */
trait HasCamTerms
{
    /** @return HasMany<LeaseCamTerm, $this> */
    public function camTerms(): HasMany
    {
        return $this->hasMany(LeaseCamTerm::class);
    }

    /**
     * The CAM cost-share ceiling in force for a reconciliation year, or null if the lease has no
     * cap term for/before that year. Picks the effective-dated term with the greatest
     * effective_year ≤ the reconciled year, then resolves its ceiling.
     */
    public function resolveCamCeiling(int $reconciledYear, ?string $poolCode = null): ?float
    {
        return $this->camTermFor($reconciledYear, $poolCode)?->resolveCeiling($reconciledYear);
    }

    /**
     * The cap scope in force for a reconciliation year (story RC-07) — `total` by default, so every
     * term written before this keeps capping exactly what it capped.
     */
    public function camCapScope(int $reconciledYear, ?string $poolCode = null): string
    {
        $term = $this->camTermFor($reconciledYear, $poolCode);

        // Both columns are NOT NULL with defaults, so a term always answers; only the ABSENCE of a
        // term needs a fallback.
        return $term === null ? LeaseCamTerm::SCOPE_TOTAL : (string) $term->cap_scope;
    }

    /** Does this lease's cap bank unused headroom for later years? */
    public function camCapCarriesForward(int $reconciledYear, ?string $poolCode = null): bool
    {
        $term = $this->camTermFor($reconciledYear, $poolCode);

        return $term !== null && (bool) $term->cap_carry_forward;
    }

    /**
     * Headroom banked by earlier reconciled years (story RC-07).
     *
     * Read from the ALLOCATIONS rather than recomputed from the terms: a cap renegotiated in year
     * three must not retroactively change what year one banked, and the allocation is the record of
     * what the tenant was actually billed under. Headroom already drawn on is netted off, so it
     * cannot be spent twice.
     *
     * Only years BEFORE the one being reconciled — a year cannot bank into itself.
     */
    public function camCapHeadroomBankedBefore(int $reconciledYear, ?string $poolCode = null): float
    {
        $rows = CamAllocation::query()
            ->where('lease_id', $this->id)
            // SCOPED TO THE POOL THE CAP GOVERNS. It filtered on the year alone, so a lease trading
            // in two pools banked headroom under one and spent it against the other — grease-trap
            // headroom drawn down against the CAM ceiling. A term that names a pool banks only
            // within it; a term that names none governs every pool without one of its own, and
            // banks across exactly those.
            ->whereHas('pool', fn ($q) => $q
                ->where('period_year', '<', $reconciledYear)
                ->when($poolCode !== null, fn ($p) => $p->where('pool_code', $poolCode)))
            ->get();

        return round(max(0.0, (float) $rows->sum('cap_headroom_banked') - (float) $rows->sum('cap_headroom_used')), 2);
    }

    /**
     * The CAM term governing a POOL in a YEAR — one lookup, five callers.
     *
     * **A cap belongs to a recovery pool, not to a year.** Yardi runs several pools per property —
     * CAM, real-estate tax, insurance, utilities, HVAC — "each with a different recovery basis, a
     * different set of participants AND A DIFFERENT CAP". This resolved on the year alone, so every
     * pool reconciling that year applied the SAME ceiling independently: measured on the demo books,
     * Zööba trades in `cam` and `fc_grease` in 2025 under a 45,000 term and each pool caps at
     * 45,000 — 90,000 borne against a contract that says 45,000.
     *
     * A term naming the pool WINS; a term naming none is the fallback that governs any pool without
     * one of its own. Every row written before 2026-09-01 has a null `pool_code`, so an install that
     * never wrote a pool-specific term resolves exactly what it always did.
     *
     * Effective-dating is resolved WITHIN the winning scope, not across both — the most specific
     * scope wins outright, so a 2027 portfolio-wide term does not supersede a 2025 term written for
     * this pool. Otherwise a general renegotiation would silently discard a pool's own clause.
     */
    private function camTermFor(int $reconciledYear, ?string $poolCode = null): ?LeaseCamTerm
    {
        $latest = fn ($query) => $query
            ->where('effective_year', '<=', $reconciledYear)
            ->orderByDesc('effective_year')
            ->first();

        if ($poolCode !== null) {
            $own = $latest($this->camTerms()->where('pool_code', $poolCode));

            if ($own !== null) {
                return $own;
            }
        }

        return $latest($this->camTerms()->whereNull('pool_code'));
    }

    /**
     * Is this lease carved OUT of the share denominator — Yardi's *adjusted* basis?
     *
     * The anchor deal. An anchor negotiates a contribution its floor area would never justify;
     * leaving that area in the divisor dilutes every in-line tenant's share, so the pool
     * under-recovers by most of its value and the landlord absorbs the difference silently.
     * Carved out, the anchor takes the share its contract NAMES and the in-line tenants divide what
     * is left over their own area.
     *
     * Meaningless without a stated share — a lease out of the divisor has no area basis left to
     * derive one from — which `CamReconciliationService` refuses rather than allocating it nothing.
     */
    public function isCarvedOutOfCamDenominator(int $reconciledYear, ?string $poolCode = null): bool
    {
        return (bool) $this->camTermFor($reconciledYear, $poolCode)?->excluded_from_denominator;
    }

    /**
     * The ledger accounts this lease's clause carves out of ITS OWN share (slice 3).
     *
     * A per-lease exclusion — "my share excludes capital items and the management fee" — and not a
     * pool decision: the neighbours keep paying on the whole pool, because their own leases say
     * "your pro-rata share of the pool" and re-cutting them to cover one tenant's carve-out would
     * over-bill them against their own terms. The landlord bears the difference, which is the same
     * rule a stated share below the area share already follows.
     *
     * Resolved on the same term the cap is, so it is per pool and effective-dated for free.
     *
     * @return list<int>
     */
    public function camExcludedAccountIds(int $reconciledYear, ?string $poolCode = null): array
    {
        $ids = $this->camTermFor($reconciledYear, $poolCode)?->excluded_account_ids ?? [];

        // A JSON column is whatever was written into it. Coerce, so one bad row cannot make a
        // `whereIn` match on a string and silently exclude nothing.
        return array_values(array_filter(array_map('intval', (array) $ids)));
    }

    /**
     * The recovery share this lease's contract NAMES, if it names one (story RC-03).
     *
     * A stated share beats any derived one: no denominator can produce a percentage the parties
     * simply agreed, and Egyptian commercial leases state one often enough that deriving over the
     * top of it was quietly billing a different number from the contract.
     *
     * Resolved the same way the cap is — the latest term effective on or before the year being
     * reconciled — so a share that was renegotiated mid-term applies from the year it was agreed.
     */
    public function statedCamSharePct(int $reconciledYear, ?string $poolCode = null): ?float
    {
        $term = $this->camTermFor($reconciledYear, $poolCode);

        return $term?->stated_share_pct !== null ? (float) $term->stated_share_pct : null;
    }
}
