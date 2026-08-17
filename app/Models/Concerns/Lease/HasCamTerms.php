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
    public function resolveCamCeiling(int $reconciledYear): ?float
    {
        return $this->camTermFor($reconciledYear)?->resolveCeiling($reconciledYear);
    }

    /**
     * The cap scope in force for a reconciliation year (story RC-07) — `total` by default, so every
     * term written before this keeps capping exactly what it capped.
     */
    public function camCapScope(int $reconciledYear): string
    {
        $term = $this->camTermFor($reconciledYear);

        // Both columns are NOT NULL with defaults, so a term always answers; only the ABSENCE of a
        // term needs a fallback.
        return $term === null ? LeaseCamTerm::SCOPE_TOTAL : (string) $term->cap_scope;
    }

    /** Does this lease's cap bank unused headroom for later years? */
    public function camCapCarriesForward(int $reconciledYear): bool
    {
        $term = $this->camTermFor($reconciledYear);

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
    public function camCapHeadroomBankedBefore(int $reconciledYear): float
    {
        $rows = CamAllocation::query()
            ->where('lease_id', $this->id)
            ->whereHas('pool', fn ($q) => $q->where('period_year', '<', $reconciledYear))
            ->get();

        return round(max(0.0, (float) $rows->sum('cap_headroom_banked') - (float) $rows->sum('cap_headroom_used')), 2);
    }

    /** The latest CAM term effective on or before a year — one lookup, four callers. */
    private function camTermFor(int $reconciledYear): ?LeaseCamTerm
    {
        return $this->camTerms()
            ->where('effective_year', '<=', $reconciledYear)
            ->orderByDesc('effective_year')
            ->first();
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
    public function statedCamSharePct(int $reconciledYear): ?float
    {
        $term = $this->camTermFor($reconciledYear);

        return $term?->stated_share_pct !== null ? (float) $term->stated_share_pct : null;
    }
}
