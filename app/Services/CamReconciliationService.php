<?php

namespace App\Services;

use App\Enums\AssessmentBasis;
use App\Enums\UnitOwnershipStatus;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Models\LeaseCamTerm;
use App\Models\Unit;
use App\Models\UnitOwnership;
use App\Support\OpsLog;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CamReconciliationService
{
    /**
     * Generate one CamAllocation per active lease in the pool's asset,
     * with each lease's pro-rata share computed from leased sqm.
     *
     * Area is the lease's TOTAL over every unit on it (Lease::totalAreaSqm()), not the master
     * unit's — see that method for why reading the master alone mis-distributes the pool while
     * leaving the tie-out green.
     *
     * Allocated amount = (lease total sqm / total leased sqm) * total_actual_expense.
     * Estimated paid = (lease total sqm / total leased sqm) * total_estimated_collected.
     * True-up = allocated - estimated. Positive means under-collected (tenant owes more).
     *
     * Idempotent: existing allocations are updated, not duplicated.
     */
    public function generateAllocations(CamExpensePool $pool): int
    {
        // On a RE-RUN, keep the ORIGINAL participant set AND the share basis frozen. The set is
        // pinned via $existingLeaseIds; the SHARES are pinned by REUSING each existing allocation's
        // stored pro_rata_share_pct instead of recomputing from live sqm. Otherwise a unit-area
        // edit — or a soft-deleted participant — between runs would shift the sqm denominator, and
        // with some allocations already billed (frozen) the remaining pending ones would recompute
        // against the new denominator → Σ(allocated) ≠ total_actual_expense (broken tie-out) and
        // over-/under-billed tenants. The pool freeze guard protects the expense basis; this
        // protects the area basis, its missing counterpart.
        $existing = $pool->allocations()->get(['lease_id', 'unit_ownership_id', 'pro_rata_share_pct']);
        $isRerun = $existing->isNotEmpty();

        $leases = $isRerun
            ? Lease::query()->whereIn('id', $existing->pluck('lease_id')->filter())->with('unit', 'units.areas')->get()
                ->concat(UnitOwnership::query()->whereIn('id', $existing->pluck('unit_ownership_id')->filter())->with('unit')->get())
            : $this->participants($pool);

        // Frozen shares (as a fraction) for the re-run path; the sqm denominator for the first run.
        //
        // Keyed by AGREEMENT, not by lease id. A `pluck(..., 'lease_id')` collapses every ownership
        // row onto the key `null` — so on a mall with two sold units the second would overwrite the
        // first and both would re-run against one share. Silent, and it only shows up as a broken
        // tie-out on the second reconciliation of a pool that reconciled cleanly the first time.
        $frozenShares = $isRerun
            ? $existing->mapWithKeys(fn ($a) => [self::agreementKey($a) => $a->pro_rata_share_pct])
            : collect();

        // The pool covers a YEAR, so the area basis is the TIME-WEIGHTED area over that year
        // (LE-02): a tenant who took an extra 300 m² on 1 November has not occupied it for the
        // year and must not carry a whole year of its CAM. For every lease whose premises are
        // undated — all of them until an expansion or contraction is recorded — this is identical
        // to the flat total, so no existing pool changes basis.
        $periodStart = CarbonImmutable::create((int) $pool->period_year, 1, 1)->startOfDay();
        $periodEnd = $periodStart->endOfYear()->startOfDay();

        // The DENOMINATOR the shares divide by (story RC-03). `occupied` — the legacy basis and
        // the default — is the summed area of the participants, which recovers 100% of the pool
        // from whoever happens to be trading. That is what some leases say; others say share of
        // GROSS leasable area, which leaves the vacancy with the landlord.
        $totalSqm = $isRerun ? 0.0 : $this->resolveDenominator($pool, $leases, $periodStart, $periodEnd);

        if (! $isRerun && $totalSqm <= 0) {
            return 0;
        }

        // The expense the shares apportion over (story RC-04). Identical to `total_actual_expense`
        // unless the pool grosses up, which is every pool that exists today. Occupancy is measured
        // as the participants' area over the denominator actually used — the same two numbers the
        // shares divide, so the gross-up can never disagree with the apportionment it feeds.
        // On a RE-RUN the denominator is frozen with the shares, so occupancy is measured against
        // the stored one; a pool reconciled before RC-03 has none, which reads as 0 and grosses
        // nothing. Either way the basis is recomputed from the CURRENT expense.
        //
        // Re-using the stored `grossed_up_expense` here would have been the obvious shortcut and it
        // was wrong: the frozen-share guard freezes the AREA basis so a unit-area edit cannot
        // re-cut anybody's share, but a REVISED POOL EXPENSE is exactly what a re-run exists to
        // push through. Caught by `CamScenarioTest`, which pins that revision.
        $referenceSqm = $isRerun ? (float) $pool->denominator_used_sqm : $totalSqm;

        $occupancyPct = $referenceSqm > 0
            ? $this->occupiedDenominator($leases, $periodStart, $periodEnd) / $referenceSqm * 100
            : 0.0;

        $basis = $pool->apportionmentBasis($occupancyPct);

        // ── STATED SHARES MAY NOT PROMISE AWAY MORE THAN THE POOL (2026-08-19, pre-staging QA F-08) ──
        //
        // A lease whose contract names its percentage takes that percentage (RC-03), and the other
        // participants keep their own area share — deliberately, because their leases say "your
        // pro-rata share" and re-cutting them to cover someone else's negotiated discount would
        // over-bill them against their own terms. A stated share BELOW the area share therefore
        // recovers less of the pool, with the landlord bearing the difference; that is the
        // behaviour `CamDenominatorTest` pins and it is not changed here.
        //
        // What was never considered is the OTHER direction. Measured on four equal 250 m² shops
        // with one stated at 40%: Σ shares 115%, and 1,150,000 recovered against 1,000,000 of
        // actual common cost — tenants billed 15% more than the cost incurred, on the tenant-facing
        // recovery invoice, with nothing reporting it. Recovery is capped at actual cost in almost
        // every service-charge clause, so that is a commercial and legal exposure rather than a
        // rounding question.
        //
        // Refused rather than clamped: scaling somebody's agreed percentage down is a decision no
        // engine may take on its own, and billing them all in full is the over-recovery itself. The
        // operator reviews the clauses and reconciles again.
        //
        // The test is on the TOTAL that would be allocated, not on the stated shares alone. A single
        // lease stated at 12.5% against a 2% area share over-recovers by 10.5% while the stated
        // figures sum to well under 100 — so a guard reading only `Σ stated` would have passed the
        // very pool that failed (measured: Σ allocated 1,102,168.95 against 1,000,000 of cost).
        //
        // A re-run reuses its frozen shares and never reaches this.
        $statedShares = $isRerun
            ? collect()
            : $this->statedShares($pool, $leases, $totalSqm, $periodStart, $periodEnd);
        $projectedShare = $isRerun
            ? 0.0
            : $this->projectedTotalShare($leases, $statedShares, $totalSqm, $periodStart, $periodEnd);

        if ($projectedShare > 1.000001) {
            throw new \DomainException(__('admin.cam.errors.stated_shares_exceed_pool', [
                'total' => number_format($projectedShare * 100, 2),
            ]));
        }

        return DB::transaction(function () use ($pool, $leases, $totalSqm, $isRerun, $frozenShares, $periodStart, $periodEnd, $basis, $statedShares) {
            $count = 0;
            $allocatedTotal = 0.0;

            foreach ($leases as $lease) {
                // An ownership brings no CAM CLAUSE — no stated share, no ceiling, no controllable
                // carve-out, no banked headroom. Those are terms negotiated into a lease, and a sale
                // has none of them, so it takes the plain pro-rata path below rather than answering
                // each of them with a neutral value at runtime.
                $isLease = $lease instanceof Lease;

                if ($isRerun) {
                    // Reuse the established share — never recompute from live sqm on a re-run.
                    $share = (float) ($frozenShares[self::agreementKeyFor($lease)] ?? 0) / 100;
                    if ($share <= 0) {
                        continue;
                    }
                } else {
                    // A lease whose contract NAMES its percentage wins over any derived one — no
                    // denominator can produce a number the parties simply agreed (RC-03). Common in
                    // Egyptian leases, and previously unrepresentable.
                    $stated = $statedShares->get(self::agreementKeyFor($lease));

                    if ($stated !== null) {
                        $share = (float) $stated;
                    } else {
                        $sqm = self::areaForPeriod($lease, $periodStart, $periodEnd);
                        if ($sqm <= 0) {
                            continue;
                        }

                        // A neighbour's share is NOT re-cut because someone else's contract names a
                        // percentage. Their own lease says "your pro-rata share of the pool", and
                        // charging them more because a third party negotiated a discount would
                        // over-bill them against their own terms. So a stated share BELOW the area
                        // share simply means less of the pool is recovered and the landlord bears
                        // the difference — the deliberate behaviour pinned by `CamDenominatorTest`.
                        //
                        // The harmful direction is the other one, and it is guarded before the loop:
                        // stated shares that together exceed the pool are refused rather than billed.
                        $share = $sqm / $totalSqm;
                    }
                }

                $allocated = round($basis * $share, 2);
                // What this tenant actually paid in estimates (story RC-05). On `billed` the
                // figure comes from the invoices themselves, so the estimate RECONCILED and the
                // estimate BILLED are the same number by construction. On `stated` — every pool
                // that already exists — it stays the pro-rata slice of a hand-typed total, which
                // is what those years were reconciled against and must keep being.
                // What this participant already paid toward the pool. For an OWNER that is his
                // monthly صيانة: an assessment and a tenant's service-charge estimate are the same
                // economic act — recovery of common cost — billed under the same charge type, which
                // is exactly what this query sums. That is what makes an ownership a participant
                // rather than a second parallel system.
                $estimated = $pool->estimate_basis === CamExpensePool::BASIS_BILLED
                    ? app(SyncCamPoolFromLedgerService::class)->estimateBilledFor($pool, $lease)
                    : round((float) $pool->total_estimated_collected * $share, 2);

                // Cap: a ceiling the tenant's CAM cost share can't exceed this year. It hits ONLY
                // the true-up + the admin-fee base — allocated_amount stays uncapped, so the
                // books-check's Σ allocated = total_actual_expense tie-out is untouched. The
                // landlord ABSORBS the difference (cap_absorbed). No cap term ⇒ ceiling null ⇒
                // capped_cost = allocated ⇒ true-up + fee byte-identical to the no-cap slice.
                $ceiling = $isLease ? $lease->resolveCamCeiling((int) $pool->period_year) : null;

                // How the cap bites (story RC-07). Two refinements over "cap the whole share":
                //
                //   SCOPE — most clauses cap only CONTROLLABLE costs and expressly carve out rates,
                //   insurance and utilities, because a landlord cannot be asked to absorb a levy it
                //   does not set. The uncontrollable part passes through above the ceiling.
                //
                //   CARRY-FORWARD — a cumulative cap banks the headroom of a year that came in
                //   under, so a later spike can draw on it. Without it, running the centre cheaply
                //   for three years earns no credit in the fourth.
                $capScope = $isLease ? $lease->camCapScope((int) $pool->period_year) : null;
                $carryForward = $isLease && $lease->camCapCarriesForward((int) $pool->period_year);

                $controllableShare = $capScope === LeaseCamTerm::SCOPE_CONTROLLABLE
                    ? $pool->controllableShare()
                    : 1.0;

                $cappable = round($allocated * $controllableShare, 2);
                $passThrough = round($allocated - $cappable, 2);

                $banked = $carryForward && $ceiling !== null
                    ? $lease->camCapHeadroomBankedBefore((int) $pool->period_year)
                    : 0.0;

                $effectiveCeiling = $ceiling !== null ? $ceiling + $banked : null;

                $cappedCappable = $effectiveCeiling !== null ? min($cappable, $effectiveCeiling) : $cappable;
                $cappedCost = round($cappedCappable + $passThrough, 2);
                $capAbsorbed = round($allocated - $cappedCost, 2);

                // What the cap did, recorded so next year's headroom comes from the books rather
                // than from re-running terms that may since have been renegotiated.
                $headroomUsed = $effectiveCeiling !== null
                    ? round(min($banked, max(0.0, $cappable - $ceiling)), 2)
                    : 0.0;
                $headroomBanked = $effectiveCeiling !== null
                    ? round(max(0.0, $ceiling - $cappedCappable), 2)
                    : 0.0;

                $trueUp = round($cappedCost - $estimated, 2);

                // Admin fee = the routine % the landlord adds on top of the recovered pool
                // (operator default 10%, 14% VAT). It is a SIBLING of the true-up — its own
                // invoice line + charge, never folded into true_up_amount — so allocated_amount
                // stays the pass-through cost the books-check ties out against. Base = the CAPPED
                // (net) cost the tenant actually bears, so the fee can't re-breach the cap. A null
                // admin_fee_pct (existing pools, no clause) yields 0 → byte-identical billing.
                $adminFeePct = (float) ($pool->admin_fee_pct ?? 0);
                $adminFee = round($adminFeePct * $cappedCost, 2);
                $adminFeeVat = Vat::onType($adminFee, 'cam_admin_fee');

                // Lock the existing row inside the txn so the status check below
                // sees committed truth — a concurrent bill() that flipped it to
                // 'billed' between a stale read and our save would otherwise be
                // clobbered back to 'pending' and re-billed (double charge/credit).
                $link = self::allocationLink($lease);

                $allocation = CamAllocation::query()
                    ->where('cam_expense_pool_id', $pool->id)
                    ->where(key($link), current($link))
                    ->lockForUpdate()
                    ->first();

                // Never re-touch an allocation that's already been billed.
                if ($allocation && $allocation->status !== 'pending') {
                    continue;
                }

                $allocation ??= new CamAllocation(['cam_expense_pool_id' => $pool->id] + $link);

                $allocation->fill([
                    'pro_rata_share_pct' => round($share * 100, 4),
                    'allocated_amount' => $allocated,
                    'estimated_paid' => $estimated,
                    'cap_amount' => $ceiling,           // the resolved ceiling that applied (null = uncapped)
                    'capped_cost_amount' => $cappedCost,
                    'cap_absorbed_amount' => $capAbsorbed,
                    'cap_headroom_used' => $headroomUsed,
                    'cap_headroom_banked' => $headroomBanked,
                    'true_up_amount' => $trueUp,
                    'admin_fee_amount' => $adminFee,
                    'admin_fee_vat_amount' => $adminFeeVat,
                    // Next year's proposed monthly estimate (story RC-05). Proposing is not
                    // applying: it is written here so the operator can review the whole mall's
                    // proposals side by side, and `ApplyCamEstimateService` is what puts an
                    // accepted one on the lease's charge schedule.
                    //
                    // Based on the CAPPED cost — what the tenant actually bears — not the uncapped
                    // allocation, or a capped tenant would be asked to pre-pay an amount their own
                    // clause forbids the landlord to charge.
                    'proposed_monthly_estimate' => round($cappedCost / 12, 2),
                    'status' => 'pending',
                ]);
                $allocation->save();
                $allocatedTotal += $allocated;
                $count++;
            }

            // What the landlord bears itself — the part of the pool no lease's share reached.
            //
            // Under `occupied` this is 0.00 (the shares sum to 100% by construction) and nothing
            // about the books changes. Under `gla` it is the vacancy: real money, previously
            // invisible, and previously read by the books tie-out as DRIFT. Storing it is what lets
            // `Σ allocated + unrecovered = total` stay a hard check instead of being loosened.
            //
            // A NEGATIVE value means the pool over-recovered — stated shares that sum past 100%.
            // That is a data problem worth seeing, so it is recorded rather than clamped to zero.
            // The basis actually applied and what the landlord bore are OUTPUTS of this run, so
            // they are rewritten every time — including a re-run, which exists precisely to push a
            // revised expense through. Leaving them frozen would have left
            // `Σ allocated + unrecovered = total` failing after any revision, silently.
            //
            // `denominator_used_sqm` is the exception: it is the frozen area basis, and re-deriving
            // it would let a unit-area edit re-cut shares the guard exists to protect.
            $written = [
                'grossed_up_expense' => round($basis, 2),
                // Measured against what the landlord ACTUALLY SPENT, never against the grossed
                // basis: gross-up changes how the cost is shared, not how much of it exists.
                'landlord_unrecovered_amount' => round((float) $pool->total_actual_expense - $allocatedTotal, 2),
            ];

            if (! $isRerun) {
                $written['denominator_used_sqm'] = round($totalSqm, 2);
            }

            $pool->forceFill($written)->save();

            return $count;
        });
    }

    /**
     * The leases that participate in THIS pool (story RC-02).
     *
     * A property can run several pools in a year — CAM, real-estate tax, insurance, a food-court
     * pool — and they do not share a participant set. Yardi's own example is exactly that: everyone
     * shares CAM, but only the food court shares grease-trap cleaning
     * (03-yardi-recoveries-percentage-rent.md §A2).
     *
     * `all` is the default and reproduces the single-pool behaviour exactly. `area` narrows to the
     * leases whose units sit in one zone — read from `units.area_id` rather than a hand-kept lease
     * list, so a lease that moves units leaves and joins the right pool on its own. Consulting the
     * `lease_unit` PIVOT rather than only the denormalised master `unit_id` matters here: a
     * multi-unit lease whose master is outside the zone but whose annexe is inside it still
     * participates, and clamping to the master alone would silently drop it.
     *
     * @return Collection<int, Lease>
     */
    private function participants(CamExpensePool $pool)
    {
        // The membership predicate lives on the pool so the billed-estimate query subtracts from
        // exactly the set this allocates to. What stays HERE is the OCCUPANCY test — whether this
        // agreement was in the centre during the year being reconciled.
        //
        // **It used to be `status = active`, and that lost money (2026-08-17).** A tenant who traded
        // from January to August and left was not an allocation target at all, so the common-area
        // cost of the months they DID occupy was recovered from nobody — it fell to the landlord
        // silently, with nothing on any screen saying so. That is the same failure the unit-owner
        // fix corrected from the other direction, and the older comment here even described the
        // asymmetry ("a departed tenant's estimate is still part of what the pool collected")
        // without drawing the conclusion: if their estimate counts, so must their share.
        //
        // A lease now participates when it OVERLAPS the reconciled year — and `totalAreaSqmForPeriod`
        // weights it by the days it actually held its units, so a part-year tenant carries a
        // part-year share on both sides of the fraction. `draft` / `pending_approval` / `cancelled`
        // never occupied anything and are excluded outright.
        $periodStart = CarbonImmutable::create((int) $pool->period_year, 1, 1)->startOfDay();
        $periodEnd = $periodStart->endOfYear()->startOfDay();

        $leases = $pool->participantLeaseQuery()
            ->whereNotIn('status', ['draft', 'pending_approval', 'cancelled'])
            ->whereDate('commencement_date', '<=', $periodEnd->toDateString())
            ->where(fn ($q) => $q
                ->whereNull('expiry_date')
                ->orWhereDate('expiry_date', '>=', $periodStart->toDateString()))
            ->with('unit', 'units.areas')
            ->get();

        // Sold units are participants too. An owner occupies common area, so leaving him out did not
        // merely under-recover — it moved his share onto the remaining tenants, who paid it without
        // anything on any screen saying so. Only HANDED-OVER ownerships whose tenure covers the
        // reconciled year: before handover the operator still carries the unit's cost.
        $ownerships = UnitOwnership::query()
            ->where('asset_id', $pool->asset_id)
            ->where('status', UnitOwnershipStatus::HandedOver->value)
            ->covering(CarbonImmutable::create((int) $pool->period_year, 12, 31))
            ->with('unit')
            ->get();

        return $leases->concat($ownerships);
    }

    /** A stable key per participant — `L:12` / `O:7` — so an ownership cannot collide on a null. */
    private static function agreementKey(CamAllocation $allocation): string
    {
        return $allocation->lease_id !== null ? 'L:'.$allocation->lease_id : 'O:'.$allocation->unit_ownership_id;
    }

    /** The same key, from the participant rather than from a stored allocation. */
    private static function agreementKeyFor($participant): string
    {
        return $participant instanceof Lease ? 'L:'.$participant->id : 'O:'.$participant->id;
    }

    /** The FK this participant's allocation is keyed by. */
    private static function allocationLink($participant): array
    {
        return $participant instanceof Lease
            ? ['lease_id' => $participant->id]
            : ['unit_ownership_id' => $participant->id];
    }

    /**
     * The participant's area over the reconciled year.
     *
     * A lease is time-weighted across every unit on it (an expansion part-way through the year must
     * not carry a whole year of CAM). An ownership is one unit, and its tenure is already what
     * decides whether it participates at all, so its area is the unit's.
     */
    private static function areaForPeriod($participant, CarbonImmutable $start, CarbonImmutable $end): float
    {
        return $participant instanceof Lease
            ? $participant->totalAreaSqmForPeriod($start, $end)
            : (float) ($participant->unit?->area_sqm ?? 0);
    }

    /**
     * The area every share divides by (story RC-03).
     *
     * @param  Collection<int, Lease>  $leases
     */
    private function resolveDenominator(
        CamExpensePool $pool,
        $leases,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): float {
        return match ($pool->denominator_basis) {
            // The property's GROSS leasable area: vacancy stays with the landlord, which is what a
            // lease means when it says "share of the total leasable area of the centre". Falls back
            // to the summed unit areas when no GLA is declared — the two are the same intent, and
            // `Asset::totalUnitAreaSqm()` is the one already used for occupancy.
            // On an AREA-scoped pool the property's GLA is the wrong denominator by an order of
            // magnitude: it would spread a food-court pool across the whole centre and charge the
            // food court a few percent of its own costs. The zone's own leasable area is what
            // "share of the total leasable area" means for a pool that only covers the zone.
            CamExpensePool::DENOMINATOR_GLA => $pool->participant_scope === CamExpensePool::PARTICIPANTS_AREA
                && $pool->participant_area_id
                    // Day-weighted over the reconciled year, exactly like the numerator. A bare
                    // sum('area_sqm') divides a past year by today's measurements, so a
                    // remeasurement after the year ended silently moved every tenant's share of it.
                    ? self::zoneAreaForPeriod($pool->participant_area_id, $periodStart, $periodEnd)
                    : (float) ($pool->asset?->leasable_area_sqm > 0
                        ? $pool->asset->leasable_area_sqm
                        : ($pool->asset?->totalUnitAreaSqm() ?? 0)),

            // Contractually pinned. Zero or null is treated as unusable and falls through to the
            // occupied basis rather than allocating nothing — a mis-typed pool should reconcile
            // like it always did, not silently recover zero from everyone.
            CamExpensePool::DENOMINATOR_FIXED => (float) $pool->denominator_fixed_sqm > 0
                ? (float) $pool->denominator_fixed_sqm
                : $this->occupiedDenominator($leases, $periodStart, $periodEnd),

            default => $this->occupiedDenominator($leases, $periodStart, $periodEnd),
        };
    }

    /**
     * A zone's leasable area across the reconciled period, day-weighted on each unit's dated
     * measurements — the GLA counterpart of `Lease::totalAreaSqmForPeriod()`, and weighted the same
     * way so numerator and denominator answer the same question about the same year.
     *
     * The property-wide branch above still reads `Asset.leasable_area_sqm`, which is a single
     * operator-maintained column with no history at all. Dating it needs its own register (the shape
     * `unit_areas` already has) and is a separate change; recorded here so the remaining undated
     * input is stated rather than assumed away.
     */
    private static function zoneAreaForPeriod(int $areaId, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): float
    {
        $days = $periodStart->diffInDays($periodEnd) + 1;

        return (float) Unit::where('area_id', $areaId)
            ->with('areas')
            ->get()
            ->sum(fn (Unit $unit) => $unit->areaSqmDaysBetween($periodStart, $periodEnd) / $days);
    }

    /** @param  Collection<int, Lease>  $leases */
    private function occupiedDenominator($leases, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): float
    {
        return (float) $leases->sum(fn ($p) => self::areaForPeriod($p, $periodStart, $periodEnd));
    }

    /**
     * Full annual reconciliation lifecycle for every pool in a given year.
     *
     * For each draft/reconciling pool: generate allocations, optionally bill
     * each one, then bump pool status. Idempotent at every step:
     * already-billed allocations are skipped, already-reconciled pools are
     * skipped. Returns a stats array per pool so the caller (CLI / scheduled
     * job / admin action) can report exactly what happened.
     *
     * @return array<int, array{pool_id:int, asset:string, allocations:int, billed:int, status:string}>
     */
    public function autoTrueUpForYear(int $year, bool $autoBill = false): array
    {
        $pools = CamExpensePool::query()
            ->where('period_year', $year)
            ->whereIn('status', ['draft', 'reconciling'])
            ->with('asset')
            ->get();

        $report = [];
        $failed = 0;

        foreach ($pools as $pool) {
            try {
                $allocations = $this->generateAllocations($pool);
                $billed = 0;

                if ($autoBill) {
                    foreach ($pool->allocations()->where('status', 'pending')->get() as $allocation) {
                        $this->bill($allocation);
                        $billed++;
                    }
                }

                // Pool moves to 'reconciled' once allocations exist and billing was
                // requested; otherwise to 'reconciling' so the admin queue surfaces
                // it for manual review.
                $nextStatus = match (true) {
                    $autoBill && $allocations > 0 => 'reconciled',
                    $allocations > 0 => 'reconciling',
                    default => $pool->status,
                };

                if ($nextStatus !== $pool->status) {
                    $pool->update([
                        'status' => $nextStatus,
                        'reconciled_at' => $nextStatus === 'reconciled' ? now() : $pool->reconciled_at,
                    ]);
                }

                $report[] = [
                    'pool_id' => $pool->id,
                    'asset' => $pool->asset?->name ?? '—',
                    'allocations' => $allocations,
                    'billed' => $billed,
                    'status' => $pool->fresh()->status,
                ];
            } catch (\Throwable $e) {
                // One bad pool shouldn't abort the whole annual run — log + continue.
                $failed++;
                OpsLog::error('cam.pool_failed', [
                    'pool_id' => $pool->id,
                    'year' => $year,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        OpsLog::info('cam.run_complete', [
            'year' => $year,
            'pools' => $pools->count(),
            'reconciled' => count($report),
            'failed' => $failed,
            'auto_bill' => $autoBill,
        ]);

        return $report;
    }

    /**
     * Bill a single allocation for its true-up.
     *  - Positive true-up (tenant owes more) → a one-off Charge that lands on the
     *    next invoice.
     *  - Negative true-up (tenant over-paid) → a CreditNote on the tenant's
     *    account. (Previously this was a negative one-off Charge; if the credit
     *    exceeded January's other charges the invoice total went negative and
     *    Invoice::recomputeTotals() floored it to 0 — silently LOSING the credit.
     *    A CreditNote preserves it and settles future AR.)
     *
     * Idempotent + lock-safe: re-billing an already-billed allocation is a no-op.
     */
    /**
     * The participants whose contract names their own percentage, as a FRACTION, keyed by agreement.
     *
     * Read once, before the loop, because the figure is needed twice — to size the residual
     * denominator the others divide by, and to assign each stated participant its own share. Asking
     * the lease twice would be two reads of the same clause that could, in principle, disagree.
     *
     * An ownership brings no CAM clause, so it never appears here.
     *
     * @param  Collection<int, mixed>  $leases
     * @return Collection<string, float>
     */
    private function statedShares(
        CamExpensePool $pool,
        $leases,
        float $totalSqm,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): Collection {
        $fromLeases = $leases
            ->filter(fn ($participant): bool => $participant instanceof Lease)
            ->mapWithKeys(function (Lease $lease) use ($pool): array {
                $stated = $lease->statedCamSharePct((int) $pool->period_year);

                return $stated === null ? [] : [self::agreementKeyFor($lease) => $stated / 100];
            })
            // `toBase()` is load-bearing, not tidiness. `Eloquent\Collection::mapWithKeys()` only
            // degrades to a base collection when the result CONTAINS a non-model — an EMPTY result
            // stays Eloquent, and `Eloquent\Collection::merge()` calls `getKey()` on whatever it is
            // handed. So a pool with no stated lease share fatals the moment an ownership share is
            // merged in, and a pool with one works: the failure is invisible on the happy path.
            ->toBase();

        return $fromLeases->merge(
            $this->ownershipShares($leases, $totalSqm, $periodStart, $periodEnd)
        );
    }

    /**
     * The share a SOLD unit's own deed names — `unit_ownerships.assessment_basis` made live
     * (pre-staging QA, F-03).
     *
     * The column was collected on the form, validated, activity-logged and **read by no
     * calculation**: every ownership took the plain area path, so an operator who recorded a deed
     * participation of 3.5% was billed on floor area and nothing said so. The enum's own docblock
     * warns against exactly that — *"a basis that needs a number nobody typed is the
     * inert-configuration bug this codebase has already been bitten by"* — while being an instance
     * of it.
     *
     * **The basis governs the ANNUAL true-up and nothing else.** The monthly صيانة is a `charges`
     * row: an amount the parties agreed and the operator typed, not a figure derived from a
     * denominator. Reading the basis there would overwrite the schedule with a computed number.
     *
     * Three bases, and only one of them needed a decision:
     *
     *  - **`participation` / `stated`** read `participation_pct`, and both mean *a percentage of the
     *    pool* — a deed participation sums to 100 across the building, and a stated share is simply
     *    agreed. That is the same claim a lease's contractual share makes, so it flows through the
     *    same path and inherits F-08's over-recovery refusal for free: a building whose deeds
     *    over-promise is refused rather than billed.
     *  - **`area`** is the default and today's behaviour. It returns nothing here, so nothing moves.
     *  - **`purchase_value`** is the one with no obvious denominator, because a leased unit has no
     *    purchase price to sum with. See {@see purchaseValueShares()} for the reading chosen and why.
     *
     * A null or zero percentage falls back to AREA, never to zero — the enum says so, and a zero
     * share would silently excuse an owner from the common cost his neighbours are funding.
     *
     * @param  Collection<int, mixed>  $leases
     * @return Collection<string, float>
     */
    private function ownershipShares(
        $leases,
        float $totalSqm,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): Collection {
        $ownerships = $leases->filter(fn ($participant): bool => $participant instanceof UnitOwnership);

        $stated = $ownerships
            ->mapWithKeys(function (UnitOwnership $ownership): array {
                // Asked of the ENUM, never of a literal, so a fifth basis cannot be added without
                // answering which column it reads — the same rule the form's `required()` follows.
                if ($ownership->assessment_basis?->requiredColumn() !== 'participation_pct') {
                    return [];
                }

                $pct = (float) ($ownership->participation_pct ?? 0);

                return $pct > 0 ? [self::agreementKeyFor($ownership) => $pct / 100] : [];
            })
            ->toBase();

        return $stated->merge(
            $this->purchaseValueShares($ownerships, $totalSqm, $periodStart, $periodEnd)
        );
    }

    /**
     * `purchase_value` — the owner's share of common cost in proportion to what he paid for the unit.
     *
     * Used where the developer's contract set صيانة against the price. It is the only basis that has
     * no self-evident denominator in a mall that is **part let and part sold**: a lease has no
     * purchase price, so there is no total to divide by that includes everybody.
     *
     * **The reading chosen, stated rather than implied:** the purchase-value owners keep the share
     * of the pool their AREA gives them collectively, and re-cut that share among themselves BY
     * PRICE. So Σ over the cohort is identical either way and **no other participant moves** — which
     * is the same principle F-08 settled for a stated lease share: a neighbour's bill is never
     * re-cut because a third party's contract says something different. It also means this basis can
     * never itself cause an over-recovery.
     *
     * An owner with no purchase price recorded is excluded from the cohort — from both the numerator
     * and the area it re-cuts — and falls back to area, rather than being read as having paid zero.
     *
     * The alternative reading — price over the summed prices of the whole building — is only defined
     * when every unit is sold, and it silently becomes wrong the day one is let. Logged in
     * `docs/OPEN-QUESTIONS.md`; if the operator's contracts mean that instead, it is a change here
     * and nowhere else.
     *
     * @param  Collection<int, UnitOwnership>  $ownerships
     * @return Collection<string, float>
     */
    private function purchaseValueShares(
        $ownerships,
        float $totalSqm,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): Collection {
        $cohort = $ownerships->filter(fn (UnitOwnership $o): bool => $o->assessment_basis === AssessmentBasis::PurchaseValue
            && (float) $o->purchase_price > 0);

        if ($cohort->isEmpty() || $totalSqm <= 0) {
            return collect();
        }

        $priceTotal = (float) $cohort->sum(fn (UnitOwnership $o): float => (float) $o->purchase_price);
        $areaTotal = (float) $cohort->sum(fn (UnitOwnership $o): float => self::areaForPeriod($o, $periodStart, $periodEnd));

        if ($priceTotal <= 0 || $areaTotal <= 0) {
            return collect();
        }

        // The cohort's collective slice of the pool — exactly what area would have given them.
        $cohortShare = $areaTotal / $totalSqm;

        return $cohort->mapWithKeys(fn (UnitOwnership $o): array => [
            self::agreementKeyFor($o) => $cohortShare * ((float) $o->purchase_price / $priceTotal),
        ])->toBase();
    }

    /**
     * What the shares would sum to — the stated ones plus every derived one — before anything is
     * written.
     *
     * The single number the over-recovery guard turns on. Computed here rather than by summing the
     * allocations afterwards, because "afterwards" means the recovery invoices have already been
     * raised against the tenants.
     *
     * @param  Collection<int, mixed>  $leases
     * @param  Collection<string, float>  $statedShares
     */
    private function projectedTotalShare($leases, $statedShares, float $totalSqm, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): float
    {
        if ($totalSqm <= 0) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($leases as $participant) {
            $stated = $statedShares->get(self::agreementKeyFor($participant));

            if ($stated !== null) {
                $total += (float) $stated;

                continue;
            }

            $sqm = self::areaForPeriod($participant, $periodStart, $periodEnd);

            if ($sqm > 0) {
                $total += $sqm / $totalSqm;
            }
        }

        return round($total, 6);
    }

    /**
     * A plain-language breakdown of how one lease's CAM true-up is worked out — so an operator (and a
     * disputing tenant) can SEE every leg, not just the bare allocated/true-up columns. Surfaces the
     * cap legs (ceiling / capped cost / landlord-absorbed) that make the visible columns stop adding up
     * when a cap bites, plus the recovery VAT and admin-fee VAT that never appeared in the table, and
     * the net the tenant is actually invoiced (positive true-up) or credited (over-payment).
     *
     * @return array<string, mixed>
     */
    public function explainAllocation(CamAllocation $allocation): array
    {
        $pool = $allocation->pool instanceof CamExpensePool ? $allocation->pool : null;
        $recoveryVatRate = $pool ? (float) $pool->recovery_vat_rate : 0.0;
        $trueUp = (float) $allocation->true_up_amount;
        $recovery = max(0.0, $trueUp);
        $recoveryVat = round($recovery * $recoveryVatRate / 100.0, 2);
        $fee = (float) $allocation->admin_fee_amount;
        $feeVat = (float) $allocation->admin_fee_vat_amount;

        $direction = $trueUp > 0.005 ? 'recover' : ($trueUp < -0.005 ? 'credit' : 'fee_only');

        return [
            'share_pct' => (float) $allocation->pro_rata_share_pct,
            'pool_actual' => $pool ? (float) $pool->total_actual_expense : 0.0,
            'allocated' => (float) $allocation->allocated_amount,
            // A cap bit only when it actually absorbed cost; the ceiling alone (== allocated) is a no-op.
            'cap_applied' => $allocation->cap_amount !== null && (float) $allocation->cap_absorbed_amount > 0.005,
            'cap_ceiling' => $allocation->cap_amount !== null ? (float) $allocation->cap_amount : null,
            'capped_cost' => (float) $allocation->capped_cost_amount,
            'cap_absorbed' => (float) $allocation->cap_absorbed_amount,
            'estimated_paid' => (float) $allocation->estimated_paid,
            'true_up' => $trueUp,
            'recovery_vat_rate' => $recoveryVatRate,
            'recovery_vat' => $recoveryVat,
            'admin_fee' => $fee,
            'admin_fee_vat' => $feeVat,
            'direction' => $direction,
            // What the tenant is actually invoiced (recover / fee-only) — true-up + its VAT + the fee +
            // fee VAT. For a credit (over-payment), the credit note carries |true-up| + its recovery VAT
            // and the admin fee bills separately.
            'net_invoiced' => $direction === 'credit'
                ? round(abs($trueUp) * (1 + $recoveryVatRate / 100.0), 2)
                : round($recovery + $recoveryVat + $fee + $feeVat, 2),
        ];
    }

    public function bill(CamAllocation $allocation): CamAllocation
    {
        return DB::transaction(function () use ($allocation) {
            // Re-load under a row lock and re-check INSIDE the txn — two concurrent
            // bill() calls would otherwise both pass a stale status check and each
            // bill the true-up (double-bill).
            $allocation = CamAllocation::query()->lockForUpdate()->find($allocation->id);

            if (! $allocation || $allocation->status === 'billed') {
                return $allocation;
            }

            $pool = $allocation->pool;
            $year = $pool->period_year;
            $amount = (float) $allocation->true_up_amount;
            $adminFee = (float) $allocation->admin_fee_amount;
            $adminFeeVat = (float) $allocation->admin_fee_vat_amount;
            $hasFee = $adminFee > 0.005;

            $update = ['status' => 'billed'];

            if ($amount < 0) {
                $note = $this->billCredit($allocation, abs($amount), $year);

                // Auto-apply to the lease's open invoices (FIFO), restoring the
                // old negative-charge behaviour where the credit netted against
                // what the tenant owes — instead of sitting unapplied until an
                // admin remembers to click Apply. Any remainder stays on the
                // note as a standing credit (preserved, never lost).
                $this->applyCreditToOpenInvoices($note, $allocation);

                $update['billed_credit_note_id'] = $note->id;

                // The admin fee is a POSITIVE amount owed — it can't ride the credit note,
                // so it gets its own fee-only recovery invoice (created AFTER the credit is
                // applied above, so an over-collection credit never silently nets it away).
                if ($hasFee) {
                    $update['billed_admin_fee_charge_id'] =
                        $this->billAdminFee($allocation, $adminFee, $adminFeeVat, $year, null)->id;
                }

                $allocation->update($update);

                return $allocation->refresh();
            }

            if (abs($amount) < 0.005) {
                // Estimate exactly matched actual — nothing to recover or refund.
                // Settle the allocation without emitting a phantom zero-amount
                // charge (which would create a 0.00 recovery invoice / GL noise).
                // The fee is still owed on the recovered pool, so it bills on its own invoice.
                if ($hasFee) {
                    $update['billed_admin_fee_charge_id'] =
                        $this->billAdminFee($allocation, $adminFee, $adminFeeVat, $year, null)->id;
                }

                $allocation->update($update);

                return $allocation->refresh();
            }

            // Positive true-up: settle the cost recovery and (if any) the admin fee on the
            // SAME recovery invoice — one document for the tenant, the fee a second VAT-bearing line.
            [$charge, $invoice] = $this->billChargeImmediately($allocation, $amount, $year);
            $update['billed_charge_id'] = $charge->id;

            if ($hasFee) {
                $update['billed_admin_fee_charge_id'] =
                    $this->billAdminFee($allocation, $adminFee, $adminFeeVat, $year, $invoice)->id;
            }

            $allocation->update($update);

            return $allocation->refresh();
        });
    }

    /**
     * Un-bill an allocation: reverse every financial document it created and reset it to `pending`,
     * so the operator can correct the pool basis (the freeze guard's "void the billed allocations
     * first") and re-bill. Reverses:
     *  - the recovery invoice (positive true-up) — which also carries the fee line, if any;
     *  - the credit note (negative true-up) — un-applied from the invoices it settled, then voided;
     *  - the fee-only invoice (negative / zero true-up).
     * A recovery invoice the tenant has already PAID blocks the void (VoidInvoiceService refuses
     * captured cash) — refund the payment first. Idempotent + lock-safe (a non-billed allocation is
     * a no-op).
     */
    public function voidAllocation(CamAllocation $allocation): CamAllocation
    {
        return DB::transaction(function () use ($allocation) {
            $allocation = CamAllocation::query()->lockForUpdate()->find($allocation->id);
            if (! $allocation || $allocation->status !== 'billed') {
                return $allocation; // only a billed allocation can be un-billed
            }

            // The credit note first: un-apply it from the invoices it settled (re-opening their AR),
            // then void it (void refuses an applied note, so the un-apply must come first).
            if ($allocation->billed_credit_note_id) {
                $note = CreditNote::find($allocation->billed_credit_note_id);
                if ($note) {
                    $creditSvc = app(CreditNoteService::class);
                    $creditSvc->reverseAllApplications($note);
                    $creditSvc->void($note, 'CAM reconciliation voided');
                }
            }

            // Void each DISTINCT invoice backing the allocation. For a positive true-up the recovery
            // charge + the fee charge share ONE invoice (unique() collapses them); for a negative/zero
            // true-up the fee has its own fee-only invoice.
            $invoiceIds = collect([$allocation->billed_charge_id, $allocation->billed_admin_fee_charge_id])
                ->filter()
                ->map(fn ($chargeId) => InvoiceItem::where('charge_id', $chargeId)->value('invoice_id'))
                ->filter()
                ->unique();

            foreach ($invoiceIds as $invoiceId) {
                $invoice = Invoice::find($invoiceId);
                if ($invoice && ! in_array($invoice->status, ['cancelled', 'credited'], true)) {
                    app(VoidInvoiceService::class)->void($invoice, 'CAM reconciliation voided');
                }
            }

            // Reset to pending so the pool basis unfreezes (once no allocation is billed) and the
            // allocation can be re-generated + re-billed with the corrected figures.
            $allocation->update([
                'status' => 'pending',
                'billed_charge_id' => null,
                'billed_credit_note_id' => null,
                'billed_admin_fee_charge_id' => null,
            ]);

            // Voiding an allocation re-opens the pool for correction: a sealed (reconciled) pool must
            // return to 'reconciling', otherwise generateAllocations — which is blocked on
            // 'reconciled' — can't re-run the corrected figures, and the operator is stuck re-billing
            // the stale allocation. (No separate un-reconcile action exists; this is that path.)
            $pool = CamExpensePool::find($allocation->cam_expense_pool_id);
            if ($pool && in_array($pool->status, ['reconciled', 'closed'], true)) {
                $pool->update(['status' => 'reconciling', 'reconciled_at' => null]);
            }

            return $allocation->refresh();
        });
    }

    /**
     * Settle a POSITIVE true-up (tenant owes more) on a dedicated recovery
     * invoice IMMEDIATELY — never via a future-dated one_time charge that the
     * monthly engine may skip. Reconciliation runs the year after the reconciled
     * year is fully billed, AND the most likely under-collected tenant has an
     * ended-term lease (excluded from the monthly run by the active/expiry
     * filter), so deferring to a monthly run = silent lost revenue regardless of
     * the date. Mirrors the negative path, which applies the credit immediately.
     *
     * @return array{0: Charge, 1: Invoice} the anchor charge + the recovery invoice it settled
     *                                      (returned so the caller can append the admin-fee line to the same document).
     */
    private function billChargeImmediately(CamAllocation $allocation, float $amount, int $year): array
    {
        // The agreement that owes this recovery — a lease OR a unit ownership. Both implement
        // `BillableAgreement`, so the invoice below is raised through the one seam either way and
        // an owner's true-up is an ordinary receivable rather than a second billing path.
        $agreement = $allocation->agreement();
        $now = CarbonImmutable::now();
        $name = "CAM Reconciliation — {$year}";

        // The recovery is additional consideration for the same taxable service supply as the monthly
        // CAM estimate (billed at 14%), so it carries the pool's configured recovery VAT (default 14%;
        // 0% for a genuinely non-taxable pass-through). VAT rides ON TOP of the cost recovery — the
        // net `$amount` (= allocated/true-up) is unchanged, so the books-check's Σ allocated tie-out holds.
        $vatRate = (float) ($allocation->pool->recovery_vat_rate ?? 0);
        $vat = round($amount * $vatRate / 100, 2);
        $lineTotal = round($amount + $vat, 2);

        // The Charge is a traceability record + the books-check anchor only — it is
        // ALREADY settled on the recovery invoice below. It MUST be is_active=false
        // and dated to the reconciled year so the monthly billing engine (which
        // loads only is_active charges) never picks it up and double-bills the
        // true-up onto the tenant's regular monthly invoice.
        $charge = Charge::create($allocation->chargeLink() + [
            'name' => $name,
            'type' => 'other',
            'amount' => $amount,
            'currency' => 'EGP',
            'frequency' => 'one_time',
            'vat_applicable' => $vatRate > 0,
            'vat_rate' => $vatRate,
            'start_date' => CarbonImmutable::create($year, 1, 1),
            'end_date' => CarbonImmutable::create($year, 12, 31),
            'is_active' => false,
        ]);

        $invoice = app(IssueInvoiceService::class)->issue(
            agreement: $agreement,
            items: [[
                'charge_id' => $charge->id,
                'description' => $name,
                // 'cam_recovery' routes this to the dedicated CAM Recovery Revenue account
                // (إيرادات استرداد المصروفات المشتركة) in the GL, not generic misc income.
                'type' => 'cam_recovery',
                'amount' => $amount,
                'vat_rate' => $vatRate,
                'vat_amount' => $vat,
                'total' => $lineTotal,
            ]],
            issueDate: $now,
            // Period = the RECONCILED CAM YEAR, NOT the current month. The monthly
            // billing engine's idempotency is a per-lease period-OVERLAP check; if
            // this recovery invoice carried the current month's period it would
            // satisfy that guard and make the monthly run SKIP the lease's regular
            // rent invoice for the month (lost revenue). A past-year period never
            // collides with a live monthly run.
            periodStart: CarbonImmutable::create($year, 1, 1),
            periodEnd: CarbonImmutable::create($year, 12, 31),
        );

        return [$charge, $invoice];
    }

    /**
     * Bill the CAM admin fee. When $invoice is given (a positive true-up) the fee rides the
     * SAME recovery invoice as a second, VAT-bearing line; otherwise (negative/zero true-up)
     * it gets a dedicated fee-only invoice. Either way an is_active=false anchor charge is
     * created (traceability + books-check backing) and the invoice header is rebuilt from its
     * line items. The fee routes to cam_admin_fee_revenue in the GL via its item type.
     */
    private function billAdminFee(CamAllocation $allocation, float $fee, float $feeVat, int $year, ?Invoice $invoice): Charge
    {
        $agreement = $allocation->agreement();
        $now = CarbonImmutable::now();
        $name = "CAM Admin Fee — {$year}";
        $lineTotal = round($fee + $feeVat, 2);

        // Derive the rate from the money that was actually computed, rather than reading the
        // current setting. $feeVat was calculated at RECONCILE time; if the operator changes the
        // standard rate before the allocation is billed, reading the setting here would stamp the
        // line with a rate its own VAT amount contradicts — and that line is what the tenant's
        // invoice, the credit note that reverses it, and the ETA payload all quote.
        $feeVatRate = $fee > 0 ? round($feeVat / $fee * 100, 2) : Vat::EXEMPT;

        // Anchor charge — is_active=false + dated to the reconciled year so the monthly
        // engine never re-bills it, exactly like the cost charge above.
        $charge = Charge::create($allocation->chargeLink() + [
            'name' => $name,
            'type' => 'other',
            'amount' => $fee,
            'currency' => 'EGP',
            'frequency' => 'one_time',
            'vat_applicable' => true,
            'vat_rate' => $feeVatRate,
            'start_date' => CarbonImmutable::create($year, 1, 1),
            'end_date' => CarbonImmutable::create($year, 12, 31),
            'is_active' => false,
        ]);

        $feeLine = [
            'charge_id' => $charge->id,
            'description' => $name,
            'type' => 'cam_admin_fee',
            'amount' => $fee,
            'vat_rate' => $feeVatRate,
            'vat_amount' => $feeVat,
            'total' => $lineTotal,
        ];

        if ($invoice === null) {
            // Fee-only invoice (period = the reconciled CAM year, same rationale as the cost invoice).
            // It used to be created at 0/0/0 and left for the item hook to correct a moment later;
            // raising it from its line means its first persisted state is already the true one.
            $invoice = app(IssueInvoiceService::class)->issue(
                agreement: $agreement,
                items: [$feeLine],
                issueDate: $now,
                periodStart: CarbonImmutable::create($year, 1, 1),
                periodEnd: CarbonImmutable::create($year, 12, 31),
            );

            return $charge;
        }

        // A POSITIVE true-up: the fee rides the recovery invoice raised moments ago as a second,
        // VAT-bearing line. The header follows it automatically (Invoice::syncTotalsFromItems, fired
        // by InvoiceItem::saved). This service used to carry its own private rebuildInvoiceHeader() —
        // the same rule, written once here and forgotten everywhere else, which is exactly why it
        // was promoted to the model.
        InvoiceItem::create($feeLine + ['invoice_id' => $invoice->id]);

        return $charge;
    }

    /** Apply a freshly-issued credit to the lease's open invoices, oldest first. */
    private function applyCreditToOpenInvoices(CreditNote $note, CamAllocation $allocation): void
    {
        $service = app(CreditNoteService::class);

        // The agreement's own open invoices — an owner's assessments, or a lease's rent. Keyed by
        // whichever link the allocation carries, so an over-recovery is credited against the debt
        // of the party it was over-recovered from.
        $link = $allocation->chargeLink();

        $openInvoices = Invoice::query()
            ->where(key($link), current($link))
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->where('balance', '>', 0)
            ->orderBy('due_date')
            ->get();

        foreach ($openInvoices as $invoice) {
            if ((float) (CreditNote::whereKey($note->id)->value('balance') ?? 0) <= 0) {
                break;
            }
            $service->applyToInvoice($note, $invoice);
        }
    }

    /**
     * A negative true-up becomes an issued credit on the tenant's account. It carries the pool's
     * recovery VAT — the tenant over-paid a 14%-VAT monthly estimate, so refunding the over-collection
     * must reverse that output VAT too (the credit-note journalizer books Dr Sales Returns + Dr VAT
     * Payable / Cr AR). A 0% pool → a VAT-free credit, byte-identical to before this setting existed.
     */
    private function billCredit(CamAllocation $allocation, float $credit, int $year): CreditNote
    {
        $agreement = $allocation->agreement();

        $vatRate = (float) ($allocation->pool->recovery_vat_rate ?? 0);
        $vat = round($credit * $vatRate / 100, 2);
        $total = round($credit + $vat, 2);

        $note = CreditNote::create([
            'tenant_id' => $agreement->billingTenantId(),
            // A note against an OWNER's over-recovery has no lease. `asset_id` is what carries the
            // property now (2026_08_15_130000) — passed explicitly rather than left for the model to
            // derive, because there is no invoice on a standalone note to derive it from.
            'lease_id' => $allocation->lease_id,
            'asset_id' => $agreement->assetId(),
            'status' => 'issued',
            'issue_date' => now(),
            'reason' => 'adjustment',
            'reason_notes' => "CAM reconciliation credit — {$year}",
            'subtotal' => $credit,
            'vat_amount' => $vat,
            'total' => $total,
            'applied_amount' => 0,
            'balance' => $total,
            'currency' => 'EGP',
        ]);

        // The LINE, without which this document says nothing. See CreditNote::describeAs().
        $note->describeAs(__('admin.credit_notes.line_cam_recovery', ['year' => $year]), $credit, $vatRate, $vat);

        return $note;
    }
}
