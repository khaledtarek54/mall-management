<?php

namespace App\Services;

use App\Contracts\BillableAgreement;
use App\Models\Charge;
use App\Models\Lease;
use App\Support\ChargeEscalation;
use App\Support\RentableItemPricing;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The one place a lease's charge schedule is written.
 *
 * **The change this makes.** A lease's rent used to be a single row whose `amount` was
 * overwritten — by an operator's rent change, and again by the escalation sweep every year. The
 * system knew what the rent *is* and had no structured memory of what it *was*, nor any knowledge
 * of what it *will be*: a temporary six-month discount was indistinguishable from a permanent cut,
 * and next year's step existed nowhere until the night a job destroyed this year's.
 *
 * Now a change **closes the current row the day before the new one starts and opens the next**.
 * That is Yardi's model (docs/benchmarks/yardi/01-yardi-lease-administration.md §3.2) and it is
 * what the rest of the cycle needs: rent history, forward visibility, straight-line rent, a rent
 * roll, and amendments that mean something.
 *
 * **Why this is smaller than it looks.** `charges.start_date`/`end_date` already existed and
 * `MonthlyBillingService::chargeAppliesToPeriod()` already honoured them — the read path was
 * always ready for a schedule. Nothing wrote one. This is the write path, inverted; not a rewrite
 * of the module.
 *
 * **What it deliberately does NOT change:** `Lease::base_rent_monthly` still tracks the rent in
 * force, and every downstream consumer (forms, widgets, the marketing levy, reports) reads exactly
 * what it read before.
 */
class ChargeScheduleService
{
    /**
     * Set a charge type's amount from an effective date, preserving what came before.
     *
     * - No existing row → open the first one, dated from the lease commencement (today's behaviour
     *   for a freshly-seeded charge, so a first write is unchanged).
     * - The amount is already in force → no-op. Re-running a sweep, or saving a form that changed
     *   nothing, must not litter the schedule with identical rows.
     * - The row in force starts on or after the effective date → it has not billed yet, so
     *   **amend it in place** rather than leaving a zero-length predecessor behind.
     * - Otherwise → close it at `effectiveFrom - 1 day` and open the next, inheriting the old
     *   row's `end_date` so a bounded schedule stays bounded.
     *
     * @param  array<string, mixed>  $attributes  name / vat_applicable / vat_rate / frequency for a new
     *                                            row, plus any of `Charge::CARRIED_TERMS` (billing
     *                                            timing, proration, the escalation rule) — a stated
     *                                            term reaches the row in force as well as a successor
     */
    /**
     * The `charges` column that keys this agreement, and its id.
     *
     * A charge hangs off a lease OR a unit ownership and never both ({@see Charge::agreementKey()},
     * which answers the same question from the other end). Derived from the agreement's own
     * `invoiceLinkAttributes()` rather than from a match on the class, so a third kind of billable
     * agreement needs no change here.
     *
     * @return array{0: string, 1: int}
     */
    private static function keyFor(BillableAgreement $agreement): array
    {
        foreach ($agreement->invoiceLinkAttributes() as $column => $id) {
            if ($id !== null) {
                return [$column, (int) $id];
            }
        }

        throw new \LogicException('A billable agreement must name the column its charges hang off.');
    }

    public function setAmount(
        BillableAgreement $lease,
        string $type,
        float $amount,
        CarbonImmutable $effectiveFrom,
        array $attributes = [],
        string $origin = Charge::ORIGIN_MANUAL,
    ): ?Charge {
        $amount = round($amount, 2);
        // Taken as the DAY the caller states — no boundary snap here since 2026-09-13. The
        // anniversary walk and the sweep pass the anniversary itself, a typed act (Change Rent,
        // Add charge, an import row, a space change) passes the day the operator typed, and the
        // planner bills each row for the days it is in force (Trello gzwI17R0). The acts that
        // keep a month grain — a relief window, a holdover, a bay's register row, a CAM estimate
        // dated to its pool year — snap at their own door through `billingBoundary()`.
        $effectiveFrom = $effectiveFrom->startOfDay();

        $current = $this->rowInForce($lease, $type, $effectiveFrom);

        if ($current === null) {
            return $this->openFirstRow($lease, $type, $amount, $effectiveFrom, $attributes, $origin);
        }

        // A TERM the caller STATES reaches the row in force on the two branches that keep it —
        // the same-money no-op and the not-yet-started amendment. Found by review: an importer
        // row restating the seeded service charge at its own figure "with `percent 5`" reported
        // success and stored nothing, because both branches below returned before `$attributes`
        // was ever read — pre-existing for `billing_timing` and `prorate`, and the door this
        // change adds made the sentence in the importer's docblock false. Null means inherit,
        // exactly as it does on the successor row.
        $stated = array_filter(Arr::only($attributes, Charge::CARRIED_TERMS), fn ($v) => $v !== null);
        $statedDiffers = array_filter($stated, fn ($v, $k) => (string) $current->{$k} !== (string) $v, ARRAY_FILTER_USE_BOTH) !== [];

        // A RELIEF row is never the target of a STEP (2026-09-13, found by the anniversary-day
        // tests). The sweep's charge loop had that rule; its rent path did not — the step rode
        // through `LeaseRentChangeService` into this method, found the relief segment starting on
        // the anniversary and amended it in place to the full stepped rent, billing a tenant the
        // rent they had been relieved of for the rest of the window. The contract still steps:
        // the column and the event record it, and the walk re-prices the rung that RESUMES after
        // the window (`repriceResumption()`); the window's own rows are the operator's.
        if ($origin === Charge::ORIGIN_ESCALATION && $current->origin === Charge::ORIGIN_RELIEF) {
            return $current;
        }

        if ($this->sameMoney((float) $current->amount, $amount)) {
            if ($statedDiffers) {
                $current->update($stated);
            }

            return $current;
        }

        // A STATED figure supersedes every projected rung of the type that began after its date
        // and has since started (2026-09-13, found by review). Until rungs landed on the
        // anniversary, an act in the anniversary month amended the month's own rung in place;
        // now a Change Rent typed on the 15th, after the 10th's step, found the row in force to
        // be the step itself — or, back-dated to the 5th, closed the outgoing row and inherited
        // its end on the 9th, so the negotiated rent billed nine days and the projected step from
        // the OLD base the rest of the year. Those rungs were derived from the figure this act
        // restates, so the act takes their place and its row runs to where the last of them ran;
        // the re-true the act's door runs afterwards prunes the rungs still ahead and projects
        // again from the stated figure. The levy's rungs ride on the rent's and are superseded
        // with them — the re-sync a rent change runs writes the levy from the same date. The
        // walk's and the sweep's own rent writes never supersede: they write AT an anniversary and
        // nothing of theirs starts after it.
        if ($origin !== Charge::ORIGIN_ESCALATION) {
            $superseded = Charge::query()
                ->where(...self::keyFor($lease))
                ->where('type', $type)
                ->where('is_active', true)
                ->whereIn('origin', [Charge::ORIGIN_ESCALATION, Charge::ORIGIN_LEVY])
                ->whereDate('start_date', '>', $effectiveFrom->toDateString())
                ->whereDate('start_date', '<=', CarbonImmutable::today()->toDateString())
                ->orderBy('start_date')
                ->get();

            $superseded->each->update(['is_active' => false]);
        } else {
            $superseded = collect();
        }

        // A row that has not started yet has billed nothing — correct it rather than closing it
        // the day before it began, which would leave an unbillable stub in the schedule. It runs
        // to where the last rung it superseded ran.
        if ($current->start_date && CarbonImmutable::instance($current->start_date)->gte($effectiveFrom)) {
            $current->update([
                'amount' => $amount,
                'origin' => $origin,
                ...$stated,
                ...($superseded->isNotEmpty() ? ['end_date' => $superseded->last()->end_date] : []),
            ]);

            return $current;
        }

        // Past this point the row in force provably started BEFORE the effective date (the branch
        // above returned otherwise), so closing it the day before cannot produce a backwards or
        // zero-length range.
        $inheritedEnd = $superseded->isNotEmpty() ? $superseded->last()->end_date : $current->end_date;

        $current->update(['end_date' => $effectiveFrom->subDay()->toDateString()]);

        return Charge::create([
            ...$lease->invoiceLinkAttributes(),
            'name' => $attributes['name'] ?? $current->name,
            'type' => $type,
            'origin' => $origin,
            'amount' => $amount,
            'currency' => $lease->billingCurrency(),
            'frequency' => $attributes['frequency'] ?? $current->frequency,
            'vat_applicable' => $attributes['vat_applicable'] ?? $current->vat_applicable,
            'vat_rate' => $attributes['vat_rate'] ?? $current->vat_rate,
            // THE ROW'S TERMS ARE INHERITED — billing timing, proration, and since 2026-09-12 the
            // row's own escalation rule — and the list is `Charge::CARRIED_TERMS`, named once.
            // Each was spelled out here by hand before: `billing_timing` was the one EG-30 forgot
            // (an arrears service charge reverted to ADVANCE on the next rung, one month billed
            // twice), and a relief row and a renewal were each still dropping `prorate` a
            // fortnight after this method learned to keep it. A caller that STATES a term wins;
            // one that says nothing (or says null) inherits, which is what the `??` per column
            // meant.
            ...$current->carriedTerms(),
            ...$stated,
            'start_date' => $effectiveFrom->toDateString(),
            // Inherit the boundary, so closing a bounded schedule doesn't quietly make it open.
            'end_date' => $inheritedEnd,
            'is_active' => true,
        ]);
    }

    /**
     * Throw away the projected future and project it again from the clause as it NOW reads.
     *
     * What every clause edit needs and what the `Lease::updated` hook calls; also the repair for a
     * ladder that has already drifted, which is why it is a method rather than the hook's body.
     * Deactivates every not-yet-started `ORIGIN_ESCALATION` rung for the rent and the service
     * charge (a stated, manual rung survives — the prune never touches one — and so does the rung
     * that RESUMES a relief window, see `pruneProjectedLadder()`), prunes the levy rungs that rode
     * on exactly those rent rungs, then projects. A cleared clause projects nothing.
     *
     * `clause: false` is the LEVY's own half: only the future levy rungs are pruned, and the
     * projection is then a walk over an intact rent and service ladder — `setAmount()` no-ops on
     * an unchanged amount, so it writes the levy rungs and nothing else. No rent rung changes id,
     * and no relief window is walked over for a change that could not have moved it.
     *
     * `redateFrom` is the COMMENCEMENT the lease had before this save (Trello 7IgLPLGl): every
     * active row that started on it — the seeded rent and service charge, the levy's base row,
     * anything `openFirstRow()` dated to the commencement — now starts on the new one. After the
     * prune, so a base row the projection had closed at the first rung's eve is open again and
     * cannot be asked to end before it starts; before the walk, so the walk closes it at the
     * moved first anniversary. Only the rows CREATION anchors there move — `seed`, `levy`,
     * `renewal` — because on a lease commencing on the 1st a bay assigned that month, a CAM
     * estimate, a relief segment or a manual charge typed that day may share the date without
     * being about it; moving those would re-date a holding whose register row does
     * not move. A moved row that would now END before it starts (the levy base row a later
     * re-rate had closed at last month's eve, on a lease moved past it) covers nothing under the
     * new term and is deactivated rather than refused in a charge's vocabulary.
     *
     * `projectUntil` bounds the walk short of the lease's own expiry — a termination or a
     * close-out moved the expiry and the tenancy ENDS there, it does not step (see the hook).
     *
     * `chargeTypes` is a CHARGE's own half (meeting 2026-09-02, point 24): its rule changed on its
     * rows, so only its projected rungs are thrown away and re-walked — the rent ladder and the
     * levy keep their rows and ids (the walk over them is a `sameMoney` no-op), so a change to a
     * signage licence re-mints nothing on the rent. Composes with `clause: false` the way the
     * levy's half does.
     *
     * One transaction either way. The hook already runs inside the save's own, but this is also
     * the on-demand repair from a console, and a refusal from `Charge::saving` half-way through
     * the projection would otherwise leave a ladder pruned with nothing projected in its place.
     *
     * @param  list<string>  $chargeTypes
     * @return int rungs written by the re-projection
     */
    public function retrueProjectedLadder(
        Lease $lease,
        bool $clause = true,
        ?CarbonImmutable $redateFrom = null,
        ?CarbonImmutable $projectUntil = null,
        array $chargeTypes = [],
    ): int {
        return DB::transaction(function () use ($lease, $clause, $redateFrom, $projectUntil, $chargeTypes): int {
            $today = CarbonImmutable::now()->startOfDay();

            if ($clause) {
                $rentPruned = $this->pruneProjectedLadder($lease, 'base_rent', $today);

                // Every charge the projection writes a ladder for — the service charge and, since
                // point 24, any row carrying its own rule. Derived from the schedule rather than
                // listed, so a charge type that acquires a rule is pruned by having one.
                foreach ($this->typesWithProjectedRungs($lease, $today) as $type) {
                    $this->pruneProjectedLadder($lease, $type, $today);
                }

                if ($rentPruned !== []) {
                    $this->pruneProjectedLadder($lease, 'marketing', $today, Charge::ORIGIN_LEVY, $rentPruned);
                }
            } elseif ($chargeTypes === []) {
                $this->pruneProjectedLadder($lease, 'marketing', $today, Charge::ORIGIN_LEVY);
            } else {
                // A charge's own half: its rungs only. The levy is left standing too — its
                // re-walk below is a `sameMoney` no-op over an intact rent ladder.
                foreach ($chargeTypes as $type) {
                    $this->pruneProjectedLadder($lease, $type, $today);
                }
            }

            if ($redateFrom !== null && $lease->commencement_date !== null) {
                $this->redateRowsAnchoredOn($lease, $redateFrom, CarbonImmutable::instance($lease->commencement_date));
            }

            if (! $lease->escalates()) {
                return 0;
            }

            // `fresh()`, as every other caller passes: the projection reads the schedule it is
            // writing into, and must see the rows the prune just closed.
            return $this->projectTermEscalations($lease->fresh(), $projectUntil);
        });
    }

    /**
     * Every charge type on this lease with a not-yet-started projected rung — what a clause
     * re-true has to prune. The rent is walked separately (its prune drives the levy's), so it is
     * excluded here; the levy never carries `ORIGIN_ESCALATION` rungs at all. PARKING is in it
     * since 2026-09-12: its rungs are the register's projected steps (each bay by its own rule,
     * a follows-lease bay by the clause), and a clause edit must throw them away with the rest.
     *
     * @return list<string>
     */
    private function typesWithProjectedRungs(BillableAgreement $lease, CarbonImmutable $from): array
    {
        return Charge::query()
            ->where(...self::keyFor($lease))
            ->where('origin', Charge::ORIGIN_ESCALATION)
            ->where('is_active', true)
            ->whereNotNull('start_date')
            ->whereDate('start_date', '>', $from->toDateString())
            ->whereNotIn('type', ['base_rent', 'marketing'])
            ->distinct()
            ->pluck('type')
            ->all();
    }

    /**
     * Rule on a charge's annual increase — the ONE writer of a row's escalation terms on a lease
     * that already has its schedule (meeting 2026-09-02, point 24).
     *
     * The mode and its figure go onto every active recurring row of the type that has not yet
     * ended: the sweep reads the rung billing INTO each anniversary and the projection the rung
     * covering its eve, so every rung of the type must say the same thing or the clause stops at
     * whichever rung was left silent. Rows already ended are history and keep what they carried.
     * Then the type's own projected ladder is thrown away and re-walked — and nothing else is,
     * which is why this is not `retrueProjectedLadder($lease)`: the rent's rungs keep their ids
     * and a relief window on the rent is not walked over for a change to the parking.
     *
     * **A charge-level rule needs the anniversary the sweep keys on**, and the projection this
     * ends in arms it (`projectTermEscalations()`) — at the first anniversary ON OR AFTER today
     * where the lease had none, never in the past. A pointer the lease ALREADY carries is kept
     * as it stands: a late sweep's pointer sits in the past by design (the backlog model — one
     * step per run), and the walk then writes from it exactly as the rent's would.
     *
     * @return int projected rungs written
     */
    public function setEscalation(Lease $lease, string $type, string $mode, ?float $rate = null, ?float $amount = null): int
    {
        if (in_array($type, ChargeEscalation::DERIVED_TYPES, true)) {
            throw new \InvalidArgumentException("The {$type} charge's step is derived — the rent from the lease's clause, the levy from the rent — and carries no rule of its own.");
        }

        if (! in_array($mode, ChargeEscalation::MODES, true)) {
            throw new \InvalidArgumentException("Unknown escalation mode [{$mode}].");
        }

        return DB::transaction(function () use ($lease, $type, $mode, $rate, $amount): int {
            $today = CarbonImmutable::now()->startOfDay();

            $rows = Charge::query()
                ->where(...self::keyFor($lease))
                ->where('type', $type)
                ->where('is_active', true)
                ->where('frequency', '!=', 'one_time')
                ->get()
                ->filter(fn (Charge $c) => $c->end_date === null || CarbonImmutable::instance($c->end_date)->gte($today));

            foreach ($rows as $row) {
                // The model clears whichever figure the mode does not read.
                $row->update([
                    'escalation_mode' => $mode,
                    'escalation_rate' => $rate,
                    'escalation_amount' => $amount,
                ]);
            }

            // The pointer the sweep keys on is armed by the projection below (one seam for every
            // door), at the first anniversary on or after today.
            return $this->retrueProjectedLadder($lease->fresh(), clause: false, chargeTypes: [$type]);
        });
    }

    /**
     * The charge types on this lease that carry a rule of their own — what the sweep steps and
     * the projection walks beside the rent. Rent and the levy are never in it: the one is the
     * lease's clause and the other is derived from it. One-offs are not a schedule.
     *
     * @return list<string>
     */
    public function escalatingChargeTypes(Lease $lease): array
    {
        return Charge::query()
            ->where(...self::keyFor($lease))
            ->where('is_active', true)
            ->where('frequency', '!=', 'one_time')
            ->whereNotIn('type', ChargeEscalation::DERIVED_TYPES)
            ->whereNotNull('escalation_mode')
            ->where('escalation_mode', '!=', ChargeEscalation::NONE)
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->all();
    }

    /**
     * Write the whole term's contracted rent steps up front (story LS-01).
     *
     * **Why at signing rather than one anniversary at a time.** Until now the only rent that
     * existed was this year's; next year's appeared on the night a sweep created it. So nobody
     * could budget, no owner report could project, no rent roll could show a step, and an operator
     * could not review an increase before it billed four hundred tenants. Yardi writes the whole
     * ladder when the lease is abstracted (docs/benchmarks/yardi/01-yardi-lease-administration.md
     * §3.2); this does the same from the escalation terms already on the lease.
     *
     * **It does not fight the sweep.** `leases:apply-escalations` still runs each anniversary,
     * recomputes the same amount, and `setAmount()` finds that amount already in force — a no-op.
     * What the sweep keeps doing is advancing `Lease::base_rent_monthly` and
     * `next_escalation_date`, so "the rent in force" stays correct without a second writer of the
     * schedule. A projected lease and a swept one converge on identical rows.
     *
     * **`fixed_percent` AND `fixed_amount` are projected; only CPI is not** (a charge on its OWN
     * percentage or amount projects under any clause, CPI included — only a follows-lease row
     * waits for the index with the rent). CPI has no index feed
     * and inventing the number here would be inventing data — the same reason the sweep skips it.
     * An amount step has no such problem: "+EGP 4,000 a month each year" is as knowable at signing
     * as a percentage, and it is an ordinary anchor-tenant term. Excluding it was an omission rather
     * than a decision (2026-08-16) — `RentEscalationService` has applied amount steps at the
     * anniversary all along, so those leases had a rent that moved every year with **nothing in the
     * schedule saying so**: no budget, no owner projection, no rent-roll step, and the panel's own
     * heading reporting "no further steps scheduled" about a contracted increase.
     *
     * The step is sized exactly as the sweep sizes it — `rent + amount`, and **no collar**, because
     * a bound stated in percent has no meaning against a step stated in pounds. That equality is
     * what keeps the invariant above true for amount leases too: projected and swept converge.
     *
     * The marketing levy is projected in lock-step because it is a percentage of base rent; a
     * complete rent schedule beside a single-row levy would bill a future month's rent correctly
     * next to a levy derived from year one.
     *
     * **And every charge carrying a rule of its own projects ITS ladder beside the rent's**
     * (meeting 2026-09-02, point 24 — the service charge that follows the clause, a bay on
     * +500 a year, a licence on its own 8%), one rung per anniversary through
     * {@see projectChargeRung()}, sized from the rung covering each eve exactly as the rent is.
     * A lease whose own clause is `none` still projects its ruled charges; under such a clause
     * the rent and the levy are not walked at all.
     *
     * @return int rows created (rent + levy + every ruled charge)
     */
    public function projectTermEscalations(Lease $lease, ?CarbonImmutable $until = null): int
    {
        // Read the type as a plain string: `escalation_type` was created as a DB-level
        // enum('none','fixed_percent','cpi') in 2024, and static analysis still derives the
        // attribute type from that migration rather than the `->change()` that made it a varchar —
        // so a direct comparison against `fixed_amount` reads as "always false" (the same trap
        // annotated in RentEscalationService).
        $type = (string) $lease->escalation_type;

        $byPercent = $type === 'fixed_percent' && (float) $lease->escalation_rate > 0;
        $byAmount = $type === 'fixed_amount' && (float) $lease->escalation_amount > 0;
        $rentSteps = $byPercent || $byAmount;

        // Every charge carrying a rule of its own — the service charge and, since meeting
        // 2026-09-02 point 24, any row on the schedule (Yardi's per-charge grain). A lease whose
        // own clause is `none` still projects a parking bay's +500 a year.
        $chargeTypes = $this->escalatingChargeTypes($lease);

        // And the register: a bay, cage or signage face carrying a rule of its own on its holding
        // (2026-09-12). Its rungs are the SUM of the items held on each anniversary at the rate
        // each will bill that day — `RentableItemPricing` is the one arithmetic — written onto
        // the one `parking` row exactly as a charge's own rungs are written onto its type.
        $parkingSteps = RentableItemPricing::anyRuled($lease);

        if ((! $rentSteps && $chargeTypes === [] && ! $parkingSteps)
            || blank($lease->commencement_date)
            || blank($lease->expiry_date)) {
            return 0;
        }

        // The COLLARED rate, so the schedule shows the step that will actually bill (2026-09-11).
        // The projection wrote the raw rate until then and left the sweep to clamp each rung the
        // night it landed — "a standing caveat, stated" — which meant a collar edit showed
        // nothing in the schedule and a tester read the ceiling as not applied. Yardi's rent
        // step schedule IS the amounts that will bill; a fixed-percent collar is deterministic,
        // so it belongs in the rows. Same clamp the sweep applies, so the two cannot disagree
        // and the sweep's amend-in-place on the anniversary is a no-op. A fixed AMOUNT is not
        // collared (the bounds are percentages), exactly as in the sweep.
        $rate = $byPercent
            ? RentEscalationService::collar($lease, (float) $lease->escalation_rate)
            : (float) $lease->escalation_rate;
        $step = round((float) $lease->escalation_amount, 2);
        // `$until` is the CONTRACTED end when the lease's own expiry has been moved by an act that
        // ends the tenancy rather than extending it (see `Lease::updated`) — the walk stops at the
        // earlier of the two, so a termination date past the old expiry mints nothing.
        $expiry = CarbonImmutable::instance($lease->expiry_date);
        $expiry = $until !== null ? $expiry->min($until) : $expiry;
        $rent = (float) $lease->base_rent_monthly;

        // What a FOLLOWS-LEASE row inherits: the COLLARED stated rate under a percent clause (the
        // same clamp the sweep applies, so the two converge rung for rung), and nothing under an
        // index clause — an unpublished figure cannot be projected, which is the sweep's own
        // refusal to invent — or an amount clause, where a step stated in pounds is a statement
        // about the rent. `ChargeEscalation::inheritedPercent()` is that derivation, shared with
        // the register's re-sum so a bay's projected rung and its assignment-day price agree.
        $leasePercent = ChargeEscalation::inheritedPercent($lease);

        // Anchor on the lease's OWN next anniversary, not on commencement + N.
        //
        // For a lease being created the two are identical (Lease::creating arms
        // next_escalation_date = commencement + 1yr). They diverge for a lease already part-way
        // through its term — `base_rent_monthly` is the rent reached so far and
        // `next_escalation_date` is the next step due — which is exactly the case a backfill of
        // the existing portfolio hits. Projecting from commencement there would re-apply years
        // that have already been applied and date every step wrongly.
        // A CHARGE-LEVEL RULE NEEDS THE ANNIVERSARY THE SWEEP KEYS ON, and this is the one seam
        // every door reaches — the create form, the wizard, the importer, a renewal, Add charge,
        // the lease form's table. `Lease::saving` arms the pointer for the rent's own clause and
        // cannot see a charge row at creation (`escalatesAnyCharge()` is false before the lease
        // exists), so a `none`-clause lease whose service charge carried its own 4 % projected a
        // ladder and was never swept: the schedule billed 260 while `service_charge_monthly`
        // read 250 for the life of the lease (found by review). Armed at the first anniversary ON
        // OR AFTER today — never in the past, where the sweep would back-date a step over months
        // already billed — and persisted, so the sweep's `whereNotNull` finds the lease.
        if ($lease->next_escalation_date === null) {
            $next = $lease->escalationDateAfter(CarbonImmutable::instance($lease->commencement_date));
            $today = CarbonImmutable::today();

            while ($next->lessThan($today)) {
                $next = $lease->escalationDateAfter($next);
            }

            $lease->forceFill(['next_escalation_date' => $next->format('Y-m-d')])->save();
        }

        $firstStep = CarbonImmutable::instance($lease->next_escalation_date);

        // Each escalating charge projects ITS ladder beside the rent's — otherwise the forecast
        // shows the rent stepping beside a service charge the sweep will in fact raise every
        // anniversary, and the budget under-states a recorded term. The CARRIED figure per type
        // is the rung covering the eve of the first step, never a lease column: the schedule tab
        // can end or restate a service charge without touching `service_charge_monthly` (only
        // `base_rent` is barred there), and a ladder compounded from a stale figure would fight
        // the schedule it is being written into. It is read only where the eve rung is a RELIEF
        // (its amount is the concession, not the contract); a CAM re-estimate is never a base,
        // because the annual true-up re-prices it and a ladder compounded from an estimate would
        // double-adjust what the reconciliation corrects.
        $carry = [];

        foreach ($chargeTypes as $chargeType) {
            $base = $this->contractedRowBefore($lease, $chargeType, $firstStep->subDay());
            $carry[$chargeType] = $base !== null && $base->origin !== Charge::ORIGIN_CAM_ESTIMATE
                ? (float) $base->amount
                : 0.0;
        }

        // A service-only lease (rent 0, a live service charge carrying a rule) still deserves its
        // service-charge ladder; a lease with no rent and no ruled charge projects nothing,
        // exactly as before.
        if ($rent <= 0 && $chargeTypes === [] && ! $parkingSteps) {
            return 0;
        }

        $levyService = app(MarketingLevyService::class);
        $levyRate = $lease->has_marketing_levy ? $levyService->ratePercent($lease) : 0.0;

        $created = 0;

        // Anniversaries inside the term. The first step is one INTERVAL after commencement; the
        // last is whichever anniversary still starts before expiry — a lease ending mid-cycle gets
        // no step it will never reach.
        //
        // Walked one step at a time through `escalationDateAfter()` rather than
        // `$firstStep->addYears($year)`, and that was the second sibling EG-30 left behind: this
        // projects the CONTRACTED rent ladder an operator reads on the lease, and at annual steps
        // it disagreed with what `RentEscalationService` would actually bill a biennial clause —
        // the projection promising an increase in a year the sweep would not apply one. The same
        // walk also carries the anchor day, so a month-end ladder does not creep.
        // `$stepDate`, not `$step` — `$step` is already the fixed-AMOUNT increment a few lines up,
        // and shadowing it would turn every amount escalation into a date.
        $stepDate = $firstStep;

        while (true) {
            // The rung starts ON the anniversary (2026-09-13) — the day the clause names, not the
            // 1st of its month. The planner prorates the split month; `billingBoundary()` says
            // why the snap that stood here was the wrong grain for a step.
            $effective = $stepDate->startOfDay();

            if ($effective->greaterThan($expiry)) {
                break;
            }

            // The base for THIS step is the rent in force on the EVE of the anniversary — read
            // from the schedule the walk itself is writing, exactly as the sweep sizes its step
            // from the rung billing into the anniversary. A carried accumulator was identical
            // while the projection was the ladder's only writer; it stops being identical the
            // moment a rung mid-ladder is a STATED figure (a future-dated Change Rent amends a
            // rung in place and marks it `manual`), and compounding past one from the pre-stated
            // base is how a negotiated step's successors came out wrong. Falls back to the
            // carried figure when nothing covers the eve (a lease with no rent row yet).
            //
            // A RELIEF row on the eve is not the base either (2026-09-11): its amount is the
            // concession, not the contract, and stepping from it compounded the whole ladder from
            // the relieved figure. The carried figure is the contracted rent the relief was
            // granted against, so it is the base there.
            // Under a `none` clause the rent and the levy are NOT walked — the walk is here for
            // the charges below. "Write the unchanged figure and let `sameMoney` no-op" was the
            // first cut and it was wrong: the base is read off the EVE, and a STARTED rung the
            // prune kept (107,000 from 1 January) covers the anniversary itself, so writing the
            // eve's 100,000 there amended history down a step. Measured by the sibling test.
            if ($rentSteps) {
                $eveRent = $this->rowCovering($lease, 'base_rent', $effective->subDay());
                $rentBase = $eveRent !== null && $eveRent->origin !== Charge::ORIGIN_RELIEF
                    ? (float) $eveRent->amount
                    : $rent;

                // A rung snapped to the 1st under the old rule and already started IS this step;
                // stepping from it would step twice (`stepAlreadyAppliedOn()`). `$rent` here is
                // the contract's figure as the walk carries it — the column at the pointer.
                $ifApplied = $byAmount ? round($rent + $step, 2) : round($rent * (1 + $rate / 100), 2);
                $rent = match (true) {
                    $this->stepAlreadyAppliedOn($lease, 'base_rent', $eveRent, $effective, $ifApplied) => $rentBase,
                    $byAmount => round($rentBase + $step, 2),
                    default => round($rentBase * (1 + $rate / 100), 2),
                };

                // A rung the operator STATED outranks the derivation. A future-dated Change Rent
                // amended this anniversary's rung in place and flipped it `manual` — a negotiated
                // term, which re-truing the ladder must not overwrite with arithmetic. Its figure is
                // ADOPTED instead: the levy below follows it, and the next step compounds from it
                // through the eve read above, which is the contract's own reading (escalation applies
                // to the rent in force). `start >= effective` is what makes it a stated STEP — a
                // manual row that began in the past is simply the current rent, and the ladder steps
                // it normally.
                $standing = $this->rowCovering($lease, 'base_rent', $effective);
                $statedStep = $standing !== null
                    && $standing->origin === Charge::ORIGIN_MANUAL
                    && $standing->start_date !== null
                    && CarbonImmutable::instance($standing->start_date)->gte($effective);

                // An anniversary INSIDE a relief window is neither adopted nor written: the relief row
                // standing on it is the operator's concession (it carried `manual` until 2026-09-11
                // and WAS adopted — the rent, and the levy derived from it, came out at half), and
                // `setAmount()` would amend it in place. The step still happens contractually:
                // `$rent` carries it, the levy below is derived from it, and the rung that resumes
                // after the window is what the next anniversary steps from — the prune keeps it.
                $insideRelief = $standing?->origin === Charge::ORIGIN_RELIEF;

                if ($statedStep) {
                    $rent = (float) $standing->amount;
                } elseif ($insideRelief) {
                    // The clause applies to the whole contract and only the window's own rows are
                    // stated, so the rung that RESUMES after it is re-priced to the step — the
                    // figure the levy below is derived from, or the two disagree for a year.
                    $this->repriceResumption($lease, 'base_rent', $standing, $rent, $expiry);
                } elseif ($rent > 0 && $this->setAmount($lease, 'base_rent', $rent, $effective, [
                    // `$rent > 0` guards the service-only shape: a zero rent must not mint zero rent
                    // and levy rows just because the service-charge ladder earned the walk.
                    'name' => 'Base Rent',
                ], Charge::ORIGIN_ESCALATION)) {
                    $created++;
                }

                if ($rent > 0 && $levyRate > 0 && $this->setAmount($lease, 'marketing', round($rent * $levyRate / 100, 2), $effective, [
                    'name' => 'Marketing Levy',
                    'frequency' => 'monthly',
                ], Charge::ORIGIN_LEVY)) {
                    $created++;
                }
            }

            // Every charge carrying a rule steps beside the rent — each by ITS rule, read off
            // the rung billing into the anniversary, so a row that acquires or loses a rule
            // mid-ladder is honoured from that rung on.
            foreach ($chargeTypes as $chargeType) {
                $created += $this->projectChargeRung($lease, $chargeType, $effective, $expiry, $leasePercent, $carry[$chargeType], atPointer: $stepDate->equalTo($firstStep));
            }

            if ($parkingSteps) {
                $created += $this->projectParkingRung($lease, $effective, $leasePercent);
            }

            $stepDate = $lease->escalationDateAfter($stepDate);
        }

        return $created;
    }

    /**
     * The register's rung for one anniversary — the one `parking` row re-summed at what every
     * held item bills that day, each stepped by its own rule (2026-09-12).
     *
     * No rung where nothing moves: an anniversary on which no held item steps (each ruled `none`,
     * a follows-lease bay under an amount clause, a bay taken on the anniversary itself) sums to
     * the eve's figure and is left alone — `setAmount()` would no-op on the same money anyway,
     * and asking first keeps the count honest. Nothing held on the day is no rung either; the
     * assignment and release paths already dated the row's start and end. A relief window on the
     * parking row is not written into — UNREACHABLE today (the relief modal offers the rent and
     * the service charge only), stated so the walk stays out of a window if that ever widens;
     * the resumption re-pricing the charges get is deliberately not mirrored for a case nothing
     * can produce.
     *
     * @return int rungs written (0 or 1)
     */
    private function projectParkingRung(Lease $lease, CarbonImmutable $effective, ?float $leasePercent): int
    {
        $sum = RentableItemPricing::sumOn($lease, $effective, $leasePercent);

        if ($sum <= 0) {
            return 0;
        }

        $eve = $this->rowCovering($lease, 'parking', $effective->subDay());

        if ($eve === null || $this->sameMoney((float) $eve->amount, $sum)) {
            return 0;
        }

        if ($this->rowCovering($lease, 'parking', $effective)?->origin === Charge::ORIGIN_RELIEF) {
            return 0;
        }

        return $this->setAmount($lease, 'parking', $sum, $effective, [], Charge::ORIGIN_ESCALATION) ? 1 : 0;
    }

    /**
     * One charge's rung for one anniversary — the service-charge branch of 2026-09-05, made the
     * rule for every charge carrying one (meeting 2026-09-02, point 24).
     *
     * Same per-rung rounding and the same COLLARED rate as the sweep for a follows-lease row, so
     * a projected lease and a swept one converge rung for rung. No attributes: the
     * successor row inherits its name, VAT, timing, proration and the rule itself from the rung
     * in force, which is the row this lease actually bills under.
     *
     * Guarded per step: a charge bounded to end mid-term stops its ladder where it stops billing —
     * past its end no row covers the step, and `setAmount`'s latest-active fallback would
     * otherwise stretch the ended row and build an inverted range out of its inherited end date.
     * An estimate on EITHER side of the boundary stops it too (the sweep's own two-sided rule): an
     * estimate is no base for a step, and an estimate-governed step would overwrite the
     * reconciliation's answer. A STATED rung is adopted exactly as a stated rent rung is, and the
     * rent's two relief rules are mirrored: a relief eve is not the base, and a relief-covered
     * anniversary is neither adopted nor written — the resumption after the window is re-priced.
     *
     * `$carry` is the figure the walk carries for this type — the contracted amount a relief was
     * granted against — and is advanced to whatever this rung settles on.
     *
     * @return int rungs written (0 or 1)
     */
    private function projectChargeRung(
        Lease $lease,
        string $type,
        CarbonImmutable $effective,
        CarbonImmutable $expiry,
        ?float $leasePercent,
        float &$carry,
        bool $atPointer = false,
    ): int {
        $eve = $this->rowCovering($lease, $type, $effective->subDay());

        // No rung billing into the anniversary is no rule to read: a charge ended before it, or
        // never scheduled, projects nothing — the safe reading.
        if ($eve === null || $eve->origin === Charge::ORIGIN_CAM_ESTIMATE) {
            return 0;
        }

        $rule = ChargeEscalation::stepFor($eve, $lease, $leasePercent);

        if ($rule === null) {
            return 0;
        }

        $base = $eve->origin !== Charge::ORIGIN_RELIEF ? (float) $eve->amount : $carry;

        if ($base <= 0) {
            return 0;
        }

        // The pre-2026-09-13 shape: the eve rung is this anniversary's step already, snapped to
        // the 1st and started — adopted, never stepped again (`stepAlreadyAppliedOn()`). What the
        // sweep has applied so far is the service charge's COLUMN at the pointer's anniversary,
        // and the walk's own `$carry` after it (the rent reads its `$rent` the same way). The
        // review broke the first cut, which read the column at every anniversary: the column
        // never advances inside a walk, so at the SECOND anniversary a real resumption (275 =
        // 250 × 1.1) read as the step already applied and a re-true before the sweep dropped the
        // final year's step. Where the column is empty (a service charge kept on the schedule
        // alone) `$carry` stands in at the pointer too, which closes the legacy spanning-relief
        // corner only where the column is kept — stated in the predicate's docblock.
        $column = $type === 'service_charge' ? (float) $lease->service_charge_monthly : null;
        $reference = $atPointer && $column !== null && $column > 0 ? $column : $carry;

        if ($this->stepAlreadyAppliedOn($lease, $type, $eve, $effective, $reference > 0 ? ChargeEscalation::apply($reference, $rule) : null)) {
            $carry = $base;

            return 0;
        }

        $amount = ChargeEscalation::apply($base, $rule);

        $covering = $this->rowCovering($lease, $type, $effective);
        $stated = $covering !== null
            && $covering->origin === Charge::ORIGIN_MANUAL
            && $covering->start_date !== null
            && CarbonImmutable::instance($covering->start_date)->gte($effective);

        if ($stated) {
            $carry = (float) $covering->amount;

            return 0;
        }

        $carry = $amount;

        if ($covering?->origin === Charge::ORIGIN_RELIEF) {
            $this->repriceResumption($lease, $type, $covering, $amount, $expiry);

            return 0;
        }

        return $covering !== null
            && $covering->origin !== Charge::ORIGIN_CAM_ESTIMATE
            && $this->setAmount($lease, $type, $amount, $effective, [], Charge::ORIGIN_ESCALATION)
                ? 1
                : 0;
    }

    /**
     * Re-price the rung that resumes the contract after a relief window to the step the walk
     * computed for the anniversary inside it.
     *
     * `overlayWindow()` pushed that rung past the window with the amount it had at the time, and
     * the prune keeps it (it is the resumption, not a projection to throw away). When the clause
     * has since moved the step, the resumed figure is stale by exactly the difference — so it is
     * amended in place, `setAmount()`'s own rule for a row starting on the effective date. Only a
     * PROJECTED rung is touched: a `manual` row after the window is the operator's, and a window
     * running past the term resumes nothing.
     */
    private function repriceResumption(Lease $lease, string $type, Charge $relief, float $amount, CarbonImmutable $expiry): void
    {
        if ($relief->end_date === null || $amount <= 0) {
            return;
        }

        $resumes = CarbonImmutable::instance($relief->end_date)->addDay();

        if ($resumes->greaterThan($expiry)) {
            return;
        }

        $resumption = $this->rowCovering($lease, $type, $resumes);

        if ($resumption?->origin === Charge::ORIGIN_ESCALATION
            && $resumption->start_date !== null
            && CarbonImmutable::instance($resumption->start_date)->equalTo($resumes)) {
            $this->setAmount($lease, $type, $amount, $resumes, [], Charge::ORIGIN_ESCALATION);
        }
    }

    /**
     * Overlay a BOUNDED window on a charge type's schedule, so the schedule resumes by itself
     * afterwards (story LE-03).
     *
     * **The problem this solves.** A six-month rent relief was indistinguishable from a permanent
     * rent cut: an operator changed the rent down in July and kept a diary note to change it back
     * in January. If the diary failed, the tenant paid half rent forever and nothing in the system
     * objected. A bounded window ends on its own date, which is the whole point.
     *
     * **Why it is not just "setAmount twice".** The window can span a contracted step. A relief
     * running Jul–Dec over a schedule that steps up on 1 October must produce *two* relief rows —
     * one per underlying segment, each derived from the amount that segment would have billed —
     * and must resume in January at the **post-step** amount, not at the pre-July one. Closing the
     * row in force and re-opening it after the window would silently delete that October step and
     * under-bill the tenant for the rest of the term.
     *
     * So each overlapping row is trimmed around the window rather than replaced:
     *   - spans the whole window → closed before it, and a copy re-opened after it;
     *   - ends inside it         → simply closed before it;
     *   - starts inside it       → its start pushed past it;
     *   - wholly inside it       → deactivated, its money carried by the relief row that covers it.
     *
     * Originals are all mutated BEFORE any new row is created, so the write-time overlap guard on
     * `Charge` never sees a moment where two rows cover one month.
     *
     * @param  callable(float): float  $amountFor  the relieved amount, given what the segment would have billed
     * @return array{relief: list<Charge>, resumed: ?Charge}
     */
    public function overlayWindow(
        Lease $lease,
        string $type,
        CarbonImmutable $from,
        CarbonImmutable $to,
        callable $amountFor,
        string $origin = Charge::ORIGIN_MANUAL,
    ): array {
        $from = self::billingBoundary($from);
        // The window ends at a MONTH boundary too — a relief is a typed act, granted by the month
        // its modal names (`billingBoundary()`); since 2026-09-13 the planner could split the
        // month between the relief row and the resumed one, so this is the act's stated grain
        // rather than a constraint of the engine.
        $to = $to->endOfMonth();

        if ($to->lessThan($from)) {
            throw new \DomainException(__('admin.errors.relief_window_inverted'));
        }

        $overlapping = Charge::query()
            ->where(...self::keyFor($lease))
            ->where('type', $type)
            ->where('is_active', true)
            ->where('frequency', '!=', 'one_time')
            ->get()
            ->filter(function (Charge $c) use ($from, $to): bool {
                $s = $c->start_date ? CarbonImmutable::instance($c->start_date) : null;
                $e = $c->end_date ? CarbonImmutable::instance($c->end_date) : null;

                return ! ($e && $e->lessThan($from)) && ! ($s && $s->greaterThan($to));
            })
            ->sortBy(fn (Charge $c) => $c->start_date?->timestamp ?? 0)
            ->values();

        if ($overlapping->isEmpty()) {
            throw new \DomainException(__('admin.errors.relief_no_schedule', ['type' => $type]));
        }

        // Plan first, write second. Reading every segment's amount before anything is mutated is
        // what makes the step-spanning case correct — once a row is trimmed, its amount is still
        // there, but its dates no longer say which months it governed.
        $plan = $overlapping->map(function (Charge $c) use ($from, $to): array {
            $s = $c->start_date ? CarbonImmutable::instance($c->start_date) : null;
            $e = $c->end_date ? CarbonImmutable::instance($c->end_date) : null;

            return [
                'row' => $c,
                'segment_start' => $s && $s->greaterThan($from) ? $s : $from,
                'segment_end' => $e && $e->lessThan($to) ? $e : $to,
                'starts_before' => $s === null || $s->lessThan($from),
                'ends_after' => $e === null || $e->greaterThan($to),
                'inherited_end' => $e,
                'amount' => (float) $c->amount,
            ];
        })->all();

        $resumeAfter = null;

        foreach ($plan as $step) {
            /** @var Charge $row */
            $row = $step['row'];

            if ($step['starts_before'] && $step['ends_after']) {
                $row->update(['end_date' => $from->subDay()->toDateString()]);
                $resumeAfter = $step;
            } elseif ($step['starts_before']) {
                $row->update(['end_date' => $from->subDay()->toDateString()]);
            } elseif ($step['ends_after']) {
                $row->update(['start_date' => $to->addDay()->toDateString()]);
            } else {
                // Wholly inside the window: the relief row bills these months instead. Deactivated
                // rather than deleted — the contracted step stays legible in the schedule, and the
                // event payload names it.
                $row->update(['is_active' => false]);
            }
        }

        $relief = [];
        foreach ($plan as $step) {
            /** @var Charge $row */
            $row = $step['row'];

            $relief[] = Charge::create([
                ...$lease->invoiceLinkAttributes(),
                'name' => $row->name,
                'type' => $type,
                'origin' => $origin,
                'amount' => round($amountFor($step['amount']), 2),
                'currency' => $lease->billingCurrency(),
                'frequency' => $row->frequency,
                'vat_applicable' => $row->vat_applicable,
                'vat_rate' => $row->vat_rate,
                // The row's own TERMS, carried like everything else above — `Charge::CARRIED_TERMS`,
                // the one list. `setAmount()` once claimed "every successor comes through here",
                // and relief is the one that does not — it builds its rows directly. Dropping
                // `billing_timing` reverted an arrears-billed service charge to advance for the
                // relief segments only, so the crossover months billed twice or not at all;
                // dropping `prorate` made a flat licence start prorating the month relief begins
                // and ends; and a relief row without the escalation rule would have been read by
                // the ladder as a charge that stops stepping for the length of the concession.
                ...$row->carriedTerms(),
                'start_date' => $step['segment_start']->toDateString(),
                'end_date' => $step['segment_end']->toDateString(),
                'is_active' => true,
            ]);
        }

        $resumed = null;

        if ($resumeAfter !== null) {
            /** @var Charge $row */
            $row = $resumeAfter['row'];

            // The resumed row continues the contract, so it is never a relief row itself —
            // `manual`, as it always was, whatever origin the window's own rows carry. A relief
            // origin here would stop the prune re-linking the chain onto it and let nothing
            // step from it.
            $resumed = Charge::create([
                ...$lease->invoiceLinkAttributes(),
                'name' => $row->name,
                'type' => $type,
                'origin' => Charge::ORIGIN_MANUAL,
                'amount' => $resumeAfter['amount'],
                'currency' => $lease->currency ?? 'EGP',
                'frequency' => $row->frequency,
                'vat_applicable' => $row->vat_applicable,
                'vat_rate' => $row->vat_rate,
                // Same terms as the row this RESUMES — the whole point of the resumed row is that
                // the contract goes back to what it was before the relief window.
                ...$row->carriedTerms(),
                'start_date' => $to->addDay()->toDateString(),
                'end_date' => $resumeAfter['inherited_end']?->toDateString(),
                'is_active' => true,
            ]);
        }

        return ['relief' => $relief, 'resumed' => $resumed];
    }

    /**
     * The schedule for a charge type, oldest first — the operator-facing view of "what has this
     * lease been billed, and what will it be billed".
     *
     * @return Collection<int, Charge>
     */
    public function scheduleFor(BillableAgreement $lease, string $type)
    {
        return Charge::query()
            ->where(...self::keyFor($lease))
            ->where('type', $type)
            ->orderByRaw('start_date is null desc')
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * The row in force on a date — or, when none covers it, the latest active row.
     *
     * The fallback matters for the pre-schedule world and for a lease whose schedule has run out:
     * without it a rent change on such a lease would open a SECOND open-ended row alongside the
     * first, and the billing run would then find two rows matching one month.
     */
    /**
     * STOP billing a charge type from a date — the schedule's end, not a deletion.
     *
     * The row in force is closed the day before `$from` and deactivated, and every row that would
     * have started later goes with it: ending a chiller charge in September must not leave next
     * January's escalated row waiting to resume it. What was already billed stays exactly as
     * billed, because a schedule is history as much as it is a plan.
     *
     * `setAmount($lease, $type, 0, …)` is NOT the way to do this — a zero row still exists, still
     * matches the billing run, and still renders on the schedule as a live charge worth nothing.
     *
     * @return int rows closed (0 = nothing was in force, which is not an error)
     */
    public function close(BillableAgreement $lease, string $type, CarbonImmutable $from): int
    {
        $from = self::billingBoundary($from);

        return DB::transaction(function () use ($lease, $type, $from): int {
            $closed = 0;
            $current = $this->rowInForce($lease, $type, $from);

            if ($current) {
                // A row that has not started yet has billed nothing — deactivating it outright
                // beats stamping an end_date before its own start_date, which the model refuses
                // (and rightly: that range is unreadable).
                $startsLater = $current->start_date
                    && CarbonImmutable::instance($current->start_date)->gte($from);

                // **A FUTURE stop date must not stop billing NOW.** `is_active = false` removes the
                // row from `MonthlyBillingService`'s selection immediately, so ending a charge from
                // (say) 1 December silently stopped invoicing it in September — the intervening
                // months were never billed, and nothing on any screen said so. The operator's own
                // date was recorded correctly in `end_date` and then ignored.
                //
                // `end_date` alone is enough: the planner already refuses a row whose `end_date`
                // falls before the period it is billing, so the schedule stops itself on the day the
                // operator chose. The flag is only for a stop that has already arrived, where
                // leaving it active would offer a dead row in every picker.
                // …**AND AN ARREARS ROW IS STILL NEEDED BY AN INVOICE NOBODY HAS RAISED YET.**
                //
                // EG-30's arrears rows bill one cycle BEHIND the window they cover, so a service
                // charge stopped from 1 September has consumed August and August is settled on the
                // SEPTEMBER invoice. Traced on HEAD by reading `close()` against the planner, not
                // by running them — the regression test measures it: with `end_date = 2026-08-31` and
                // `is_active = false`, `MonthlyBillingService` drops the row at the top of its
                // planner (the `! $c->is_active` bar) BEFORE the covered window is computed — so
                // ending the charge on 2 September, on a mall whose billing day has not come round
                // yet, forfeits the last month the tenant actually consumed. Silently, and it is
                // the operator's own revenue.
                //
                // `end_date` is the real bound and already answers correctly: the planner tests it
                // against the window the row COVERS (August), not against the invoice's own period,
                // so the row stops itself after August whether or not the flag is set. All the flag
                // has to do is wait until the invoice that settles that window can no longer be
                // raised — one billing cycle past the stop.
                //
                // Residue, stated rather than left to be found: such a row then sits `is_active`
                // with an `end_date` in the past — exactly what the future-stop branch has always
                // left behind. The schedule tab's `state()` reads it as *ended* from the dates and
                // the planner refuses it, so nothing bills; what it does do is make
                // `pickInForce()`'s "latest active row" fallback treat it as current, which is a
                // pre-existing hazard of that branch and is widened here by one cycle, no more.
                $settledFrom = $current->billsInArrears()
                    ? $from->addMonthsNoOverflow(max($lease->billingCycleMonths(), 1))
                    : $from;

                $stopsInTheFuture = $settledFrom->isFuture();

                $current->update(match (true) {
                    $startsLater => ['is_active' => false],
                    $stopsInTheFuture => ['end_date' => $from->subDay()->toDateString()],
                    default => ['end_date' => $from->subDay()->toDateString(), 'is_active' => false],
                });

                $closed++;
            }

            $closed += Charge::query()
                ->where(...self::keyFor($lease))
                ->where('type', $type)
                ->where('is_active', true)
                ->whereNotNull('start_date')
                ->whereDate('start_date', '>=', $from->toDateString())
                ->update(['is_active' => false]);

            return $closed;
        });
    }

    /**
     * Re-lay a DERIVED type's rows from a date, from what the register says they should be.
     *
     * The parking row is a function of the holdings (`AssignRentableItemService::rebuildCharge()`),
     * and a function is re-derived, never amended: `setAmount()` moves the ONE row in force at a
     * date and leaves every later row standing, so a bay assigned from a date before a STARTED
     * anniversary rung left that rung at the old sum and the bay unbilled for a year, and a bay
     * released back-dated left the rung billing it for the rest of the term (found by review).
     * Here every row of the type from `$from` onward is replaced by the segments the caller
     * derived — each ending on the eve of the next, the last open-ended, a segment summing to
     * nothing leaving a GAP rather than a row at zero (a charge for nothing is not a charge). A
     * row already starting on a segment's date is amended in place rather than retired and
     * re-minted, so a second bay let the same month is one row with one id, exactly as
     * `setAmount()` keeps it.
     *
     * The row covering `$from` from before is bounded at its eve and stays ACTIVE when something
     * follows: it is the record of what billed for the months it still covers. When NOTHING
     * follows — the last item given back — it is ended through `close()`, whose rule is the
     * schedule's own: a stop still ahead keeps the row active for the months it covers, a stop
     * already past switches it off. `is_active => false` on a stop still ahead was the shape
     * before this (found by review): the planner drops an inactive row before it reads the end
     * date, so a bay released at the year end and recorded in June billed nothing from June.
     *
     * A relief row is never retired here — it is the operator's concession, not a derivation.
     * Anniversary rungs are NOT laid here: `projectTermEscalations()` walks them from the sweep's
     * own pointer through `projectParkingRung()`, the one seam that also arms the pointer, so the
     * caller runs the projection after this.
     *
     * @param  list<array{start: CarbonImmutable, amount: float}>  $segments  ascending by start, the first AT `$from`
     * @param  array<string, mixed>  $attributes  what a fresh row of the type carries (name, frequency, VAT)
     * @return int rows written or amended
     */
    public function relayDerivedRows(BillableAgreement $lease, string $type, CarbonImmutable $from, array $segments, array $attributes = []): int
    {
        $from = self::billingBoundary($from);
        $segments = array_values(array_map(fn (array $s): array => [
            'start' => self::billingBoundary($s['start']),
            'amount' => round((float) $s['amount'], 2),
        ], $segments));

        return DB::transaction(function () use ($lease, $type, $from, $segments, $attributes): int {
            $endedOutright = ($segments[0]['amount'] ?? 0.0) <= 0;

            if ($endedOutright) {
                // Nothing held from `$from`: the schedule's own end, with its own rule about the
                // row in force and everything after it.
                $this->close($lease, $type, $from);
            }

            $rows = Charge::query()
                ->where(...self::keyFor($lease))
                ->where('type', $type)
                ->where('is_active', true)
                ->where('origin', '!=', Charge::ORIGIN_RELIEF)
                ->orderBy('start_date')
                ->get();

            $starting = collect($segments)->pluck('start')->map(fn (CarbonImmutable $d) => $d->toDateString())->all();
            $reusable = [];

            foreach ($rows as $row) {
                $starts = $row->start_date === null ? null : CarbonImmutable::instance($row->start_date);

                if ($starts !== null && $starts->gte($from)) {
                    if (in_array($starts->toDateString(), $starting, true) && ! isset($reusable[$starts->toDateString()])) {
                        $reusable[$starts->toDateString()] = $row;
                    } else {
                        $row->update(['is_active' => false]);
                    }

                    continue;
                }

                // Started before `$from` and still running past it: bounded at the eve, and left
                // ACTIVE — the record of what billed for the months it covers.
                if (! $endedOutright && ($row->end_date === null || CarbonImmutable::instance($row->end_date)->gte($from))) {
                    $row->update(['end_date' => $from->subDay()->toDateString()]);
                }
            }

            $written = 0;

            foreach ($segments as $i => $segment) {
                if ($segment['amount'] <= 0) {
                    continue;
                }

                $next = $segments[$i + 1]['start'] ?? null;
                $end = $next === null ? null : $next->subDay()->toDateString();
                $key = $segment['start']->toDateString();

                if (isset($reusable[$key])) {
                    $reusable[$key]->update(['amount' => $segment['amount'], 'end_date' => $end, 'origin' => Charge::ORIGIN_MANUAL, 'is_active' => true]);
                    $written++;

                    continue;
                }

                Charge::create([
                    ...$lease->invoiceLinkAttributes(),
                    'name' => $attributes['name'] ?? ucfirst(str_replace('_', ' ', $type)),
                    'type' => $type,
                    'origin' => Charge::ORIGIN_MANUAL,
                    'amount' => $segment['amount'],
                    'currency' => $lease->billingCurrency(),
                    'frequency' => $attributes['frequency'] ?? 'monthly',
                    'vat_applicable' => $attributes['vat_applicable'] ?? null,
                    'vat_rate' => $attributes['vat_rate'] ?? null,
                    'billing_timing' => $attributes['billing_timing'] ?? null,
                    'prorate' => $attributes['prorate'] ?? null,
                    'start_date' => $key,
                    'end_date' => $end,
                    'is_active' => true,
                ]);

                $written++;
            }

            return $written;
        });
    }

    public function rowInForce(BillableAgreement $lease, string $type, CarbonImmutable $on): ?Charge
    {
        // Always a FRESH read: this runs inside setAmount()'s transaction, right after rows may
        // have been written, so a cached relation would hand back a stale answer.
        return self::pickInForce(
            Charge::query()->where(...self::keyFor($lease))->where('type', $type)->get(),
            $on,
        );
    }

    /**
     * Pick the row in force on a date from an already-loaded set — **the one definition** of
     * "which row governs".
     *
     * Split out from `rowInForce()` so a report can eager-load every lease's charges and answer
     * the question in memory without 3 queries per lease, while still asking it the same way the
     * writer does. A rent roll that decided "current rent" by its own rule would eventually
     * disagree with what actually bills, which is the one thing a rent roll may not do.
     *
     * @param  iterable<Charge>  $charges
     */
    public static function pickInForce(iterable $charges, CarbonImmutable $on): ?Charge
    {
        $covering = self::pickCovering($charges, $on);

        if ($covering !== null) {
            return $covering;
        }

        // Nothing covers the date — fall back to the latest active row, which is what keeps a
        // pre-schedule lease (one open-ended row) and a lease whose schedule has run out behaving
        // sensibly instead of reading as "no rent".
        $active = collect($charges)
            ->where('is_active', true)
            ->sortBy([
                fn (Charge $a, Charge $b) => ($a->start_date?->timestamp ?? 0) <=> ($b->start_date?->timestamp ?? 0),
                fn (Charge $a, Charge $b) => $a->id <=> $b->id,
            ])->values();

        // …UNLESS the date is BEFORE the schedule begins, where "latest" is the wrong end of it.
        // The seeded rows start ON the commencement, so a lease commencing on the 10th has no
        // row covering the 1st of its own first month — and the answer to "what is in
        // force before anything is" is the FIRST row, the one about to start, not the final rung
        // three years out. Measured (the lease behind Trello RV4DrGHA + jF09XB3n, and its tail on staging): the levy
        // re-sync on an ordinary save in the commencement month was handed the last projected
        // rung and overwrote it with the base levy, 400 → 50 in the final year. A read is wrong
        // the same way — a not-yet-started lease's "current rent" read as its last step.
        $first = $active->first();

        if ($first !== null && $first->start_date !== null && CarbonImmutable::instance($first->start_date)->gt($on)) {
            return $first;
        }

        return $active->last();
    }

    /**
     * The row ACTIVELY COVERING a date — `pickInForce()` WITHOUT its fallback, and the one
     * definition of "covers" both share.
     *
     * The fallback is right for billing and display — a pre-schedule lease's single open-ended
     * row, or a schedule that has run out, must still read as "the rent" — and wrong for a
     * GUARD: the escalation sweep must know whether a service charge is genuinely live on a
     * date, and "the latest row there ever was" answers yes about a charge the operator ENDED.
     *
     * @param  iterable<Charge>  $charges
     */
    public static function pickCovering(iterable $charges, CarbonImmutable $on): ?Charge
    {
        return collect($charges)
            ->where('is_active', true)
            ->filter(fn (Charge $c) => (blank($c->start_date) || CarbonImmutable::instance($c->start_date)->lte($on))
                && (blank($c->end_date) || CarbonImmutable::instance($c->end_date)->gte($on)))
            ->sortBy([
                fn (Charge $a, Charge $b) => ($a->start_date?->timestamp ?? 0) <=> ($b->start_date?->timestamp ?? 0),
                fn (Charge $a, Charge $b) => $a->id <=> $b->id,
            ])
            ->last();
    }

    /**
     * The query twin of {@see pickCovering()} — a fresh read, because the callers that need a
     * covering-only answer (the escalation sweep, the ladder projection) run inside transactions
     * that have just written rows.
     */
    public function rowCovering(BillableAgreement $lease, string $type, CarbonImmutable $on): ?Charge
    {
        return self::pickCovering(
            Charge::query()->where(...self::keyFor($lease))->where('type', $type)->get(),
            $on,
        );
    }

    /**
     * Re-price the SEEDED rows to the lease's own columns and re-true the ladder from them — the
     * schedule half of a re-price on a lease nothing has happened to yet.
     *
     * Two doors call it (2026-09-11): a DRAFT whose units changed on the form (a rate-priced rent
     * is rate × area, and `repriceFromPremises()` moves the column), and the lease importer
     * re-importing a lease with a corrected rent before it has billed or stepped. Both had left
     * the seeded row at the old figure while the lease column moved — the same shape as the
     * clause and the term, one column over. Amends the seed rows IN PLACE (`setAmount()`'s rule
     * for a row starting on or after the effective date, and the seed rows start on the
     * commencement), re-syncs the levy from the same date, then re-projects the steps from the
     * new base. Callers decide WHETHER nothing has happened — `Lease::commencementLockedBecause()`
     * and `scheduleIsStillAsCreated()` — because an act that changed the schedule (Change Rent, a
     * relief) owns the rows it wrote.
     */
    public function repriceSeededRent(Lease $lease): int
    {
        return DB::transaction(function () use ($lease): int {
            $from = CarbonImmutable::instance($lease->commencement_date);

            // Nothing to do when the seeded rows already carry the lease's figures — the signal
            // is the ROW, not whether a column just moved: the form derives a rate-priced rent
            // live from the units picked, so the column can arrive already correct while the
            // row is a save behind. A re-true for nothing would re-mint every rung (audit churn).
            $rentRow = $this->rowInForce($lease, 'base_rent', self::billingBoundary($from));
            $serviceRow = $this->rowInForce($lease, 'service_charge', self::billingBoundary($from));
            $rentSame = $rentRow !== null && $this->sameMoney((float) $rentRow->amount, (float) $lease->base_rent_monthly);
            $serviceSame = $serviceRow === null
                ? (float) $lease->service_charge_monthly <= 0
                : $this->sameMoney((float) $serviceRow->amount, (float) $lease->service_charge_monthly);

            if ($rentSame && $serviceSame) {
                return 0;
            }

            // A figure of ZERO retires the row rather than amending it to 0.00 — `seedStandardCharges()`
            // seeds no row for a zero, `createLevyCharge()` deactivates a switched-off levy, and
            // the billing run has no zero skip (a 0.00 line on every invoice). The review found
            // the rent branch had no such twin at all: a rent corrected to 0 moved the column,
            // re-priced the levy to 0, and left the seed rent row billing 10,000.
            foreach (['base_rent' => (float) $lease->base_rent_monthly, 'service_charge' => (float) $lease->service_charge_monthly] as $type => $amount) {
                if ($amount > 0) {
                    $this->setAmount($lease, $type, $amount, $from, ['name' => $type === 'base_rent' ? 'Base Rent' : 'Service Charge'], Charge::ORIGIN_SEED);
                } else {
                    Charge::query()->where(...self::keyFor($lease))->where('type', $type)->where('is_active', true)->update(['is_active' => false]);
                }
            }

            // The levy is REBUILT, not amended: every levy row is derived from the rent, and a
            // re-rate made earlier (base row closed at last month's eve, the new rate from the
            // 1st) left TWO rows, of which `createLevyCharge()` amends only the first — the second
            // then carried a percentage of the OLD rent for the rest of the term. Nothing has
            // been billed on a lease that gets here, so one row at today's rate from the
            // commencement is the honest schedule; the walk below writes the rungs on top.
            Charge::query()->where(...self::keyFor($lease))->where('type', 'marketing')->where('origin', Charge::ORIGIN_LEVY)->where('is_active', true)->update(['is_active' => false]);

            // A percentage of nothing is no row, not a 0.00 row (`createLevyCharge()` would open one).
            if ((float) $lease->base_rent_monthly > 0) {
                app(MarketingLevyService::class)->createLevyCharge($lease, $from);
            }

            return $this->retrueProjectedLadder($lease->fresh());
        });
    }

    /**
     * The CONTRACTED rung in force on a date — `rowCovering()` walked back over any relief row,
     * to the row the concession was granted against.
     *
     * A relief row's amount is the concession, not the contract, so it is never the base of a
     * step: the sweep sizing a charge's step from it stepped the concession (measured by review —
     * a flat 5,000 relief on a 20,000 service charge came out of the sweep as 6,000 for the three
     * months of the window, and `service_charge_monthly` was written as the relieved figure), and
     * the projection seeding its carried figure from it compounded the whole ladder from the
     * relieved amount. Bounded, because a window may abut another window.
     */
    public function contractedRowBefore(BillableAgreement $lease, string $type, CarbonImmutable $on): ?Charge
    {
        $row = $this->rowCovering($lease, $type, $on);

        for ($hops = 0; $row?->origin === Charge::ORIGIN_RELIEF && $row->start_date !== null && $hops < 12; $hops++) {
            $row = $this->rowCovering($lease, $type, CarbonImmutable::instance($row->start_date)->subDay());
        }

        return $row?->origin === Charge::ORIGIN_RELIEF ? null : $row;
    }

    /**
     * Move every active row that starts ON one date to another — the commencement half of a
     * term edit. Through the model, one row at a time, so `Charge::saving`'s own guards (an
     * inverted range, an overlap) still stand between a bad move and the schedule.
     *
     * @return int rows moved
     */
    private function redateRowsAnchoredOn(BillableAgreement $lease, CarbonImmutable $from, CarbonImmutable $to): int
    {
        if ($from->equalTo($to)) {
            return 0;
        }

        $rows = Charge::query()
            ->where(...self::keyFor($lease))
            ->where('is_active', true)
            ->whereIn('origin', [Charge::ORIGIN_SEED, Charge::ORIGIN_LEVY, Charge::ORIGIN_RENEWAL])
            ->whereNotNull('start_date')
            ->get()
            // Compared in PHP on the cast date, so the driver's stored text (full datetime on
            // SQLite, a bare date on MySQL — the prune's `whereIn` trap) never decides it.
            ->filter(fn (Charge $c) => CarbonImmutable::instance($c->start_date)->equalTo($from));

        foreach ($rows as $row) {
            if ($row->end_date !== null && CarbonImmutable::instance($row->end_date)->lessThan($to)) {
                $row->update(['is_active' => false]);

                continue;
            }

            $row->update(['start_date' => $to->toDateString()]);
        }

        return $rows->count();
    }

    /**
     * Deactivate a ladder's NOT-YET-STARTED projected rungs and re-open the rung they were
     * chained onto — the schedule-side half of clearing an escalation clause.
     *
     * `Lease::saving` clears the clause's COLUMNS when it is switched off, and until now nothing
     * cleared its projected future: the rungs written at signing kept billing increases for a
     * clause the operator had removed, and the sweep — which amends a wrong rung in place each
     * anniversary — never runs for a cleared clause, so nothing would ever correct them. "A field
     * the operator cannot see must not hold a value that can take effect", applied to the
     * schedule.
     *
     * Three rules, each load-bearing:
     *
     *  - **Origin-filtered.** Only rungs the projection (or the levy's lock-step) wrote are
     *    removed. A future rung an operator amended through Change Rent carries `manual` and is a
     *    STATED term — it survives, and the chain re-links around it.
     *  - **Started rungs stay.** A rung already billing is the current amount and the history it
     *    made; only `start_date > $from` goes.
     *  - **The chain is RE-LINKED.** The projection closed each rung the day before the next, so
     *    every surviving row whose end abuts a pruned rung would otherwise stop billing entirely
     *    at that boundary — worse than the escalated amount. Each extends to the next surviving
     *    row's eve (a stated future step keeps its place) or to the pruned chain's outer bound;
     *    an end that abuts no pruned rung is an operator's own bound and is not moved.
     *  - **A relief window is kept whole (2026-09-11).** `overlayWindow()` pushes a rung that
     *    starts inside a relief past the window's end, still `escalation` — the RESUMPTION of the
     *    contract after the concession. Pruned, it took the resumption with it, and the relief row
     *    ending the day before then read as "chained onto a pruned rung" and was re-linked past
     *    its own bound: measured, a rate edit on a lease with a six-month 50 % relief halved the
     *    rent for the rest of the term, and the ladder looked ordinary. So a rung starting the day
     *    after a relief row ends stays, and a relief row's end is never ours to move — both told
     *    apart by `ORIGIN_RELIEF`, which is what that origin exists for.
     *
     * @param  list<string>|null  $onlyStartDates  restrict to rungs starting on these dates — the
     *                                             levy's lock-step rungs are matched to the rent
     *                                             rungs actually pruned, so a levy row belonging
     *                                             to an operator's own future-dated rent change
     *                                             is left alone
     * @return list<string> the start dates of the rungs removed
     */
    public function pruneProjectedLadder(
        BillableAgreement $lease,
        string $type,
        CarbonImmutable $from,
        string $origin = Charge::ORIGIN_ESCALATION,
        ?array $onlyStartDates = null,
    ): array {
        // The day after each relief window on this ladder ends — a rung starting there resumes
        // the contract and is not a projection to throw away.
        $resumptions = Charge::query()
            ->where(...self::keyFor($lease))
            ->where('type', $type)
            ->where('origin', Charge::ORIGIN_RELIEF)
            ->where('is_active', true)
            ->whereNotNull('end_date')
            ->get()
            ->map(fn (Charge $c) => CarbonImmutable::instance($c->end_date)->addDay()->toDateString())
            ->all();

        $future = Charge::query()
            ->where(...self::keyFor($lease))
            ->where('type', $type)
            ->where('origin', $origin)
            ->where('is_active', true)
            ->whereNotNull('start_date')
            ->whereDate('start_date', '>', $from->toDateString())
            ->orderBy('start_date')
            ->get()
            // In PHP, not `whereIn`: the `date` cast serialises with the model's full datetime
            // format, so on SQLite the column holds 'Y-m-d H:i:s' text and a bare date string
            // never matches — green here, different on MySQL, the exact driver split this
            // codebase documents.
            ->filter(fn (Charge $c) => $onlyStartDates === null
                || in_array(CarbonImmutable::instance($c->start_date)->toDateString(), $onlyStartDates, true))
            ->reject(fn (Charge $c) => in_array(CarbonImmutable::instance($c->start_date)->toDateString(), $resumptions, true))
            ->values();

        if ($future->isEmpty()) {
            return [];
        }

        $outerEnd = $future->last()->end_date;

        foreach ($future as $rung) {
            $rung->update(['is_active' => false]);
        }

        // RE-LINK THE CHAIN. The projection closed each rung the day before the next, so every
        // remaining row whose end is the EVE OF A PRUNED RUNG was chained onto something that no
        // longer exists — left alone, its charge stops billing entirely at that boundary, which
        // is worse than the escalated amount. Each such row extends to the next surviving row's
        // eve (a stated future rung keeps its place in the chain) or, at the tail, to the pruned
        // chain's own outer bound. A row whose end matches no pruned rung's eve is an operator's
        // own bound and is not ours to move.
        $prunedStartEves = $future
            ->map(fn (Charge $c) => CarbonImmutable::instance($c->start_date)->subDay()->toDateString())
            ->all();

        $remaining = Charge::query()
            ->where(...self::keyFor($lease))
            ->where('type', $type)
            ->where('is_active', true)
            ->orderByRaw('start_date is null desc')
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            ->values();

        foreach ($remaining as $i => $row) {
            // The relief clause is belt-and-braces, and recorded as such rather than claimed:
            // with a relief's resumption kept above, no pruned rung's eve is a relief row's end,
            // so this branch is unreachable today (mutation leaves every case green). It stands
            // for the day something else prunes the row after a window.
            if ($row->end_date === null
                || $row->origin === Charge::ORIGIN_RELIEF
                || ! in_array(CarbonImmutable::instance($row->end_date)->toDateString(), $prunedStartEves, true)) {
                continue;
            }

            $next = $remaining[$i + 1] ?? null;

            $row->update(['end_date' => $next?->start_date
                ? CarbonImmutable::instance($next->start_date)->subDay()->toDateString()
                : ($outerEnd ? CarbonImmutable::instance($outerEnd)->toDateString() : null)]);
        }

        return $future
            ->map(fn (Charge $c) => CarbonImmutable::instance($c->start_date)->toDateString())
            ->values()
            ->all();
    }

    /**
     * The next scheduled change to a charge type after a date — the "what happens next" a rent
     * roll and the lease panel both report.
     *
     * @param  iterable<Charge>  $charges
     */
    public static function nextStepAfter(iterable $charges, CarbonImmutable $on): ?Charge
    {
        return collect($charges)
            ->where('is_active', true)
            ->filter(fn (Charge $c) => $c->start_date && CarbonImmutable::instance($c->start_date)->gt($on))
            ->sortBy(fn (Charge $c) => $c->start_date->timestamp)
            ->first();
    }

    /** @param  array<string, mixed>  $attributes */
    private function openFirstRow(
        BillableAgreement $lease,
        string $type,
        float $amount,
        CarbonImmutable $effectiveFrom,
        array $attributes,
        string $origin,
    ): ?Charge {
        if ($amount <= 0 && ($attributes['skip_if_zero'] ?? false)) {
            return null;
        }

        return Charge::create([
            ...$lease->invoiceLinkAttributes(),
            'name' => $attributes['name'] ?? ucfirst(str_replace('_', ' ', $type)),
            'type' => $type,
            'origin' => $origin,
            'amount' => $amount,
            'currency' => $lease->currency ?? 'EGP',
            'frequency' => $attributes['frequency'] ?? 'monthly',
            // A caller that says nothing about VAT gets the charge code's ruling, NOT zero — but it
            // gets it at BILLING time, by leaving both columns null for `Charge::resolvedVatRate()`
            // to answer. Writing the catalogue's answer here instead is the freeze EG-01 removed
            // (2026-08-22): it made the ruling permanent on the day the row was opened. Silent zero
            // remains the thing to avoid, and null is not zero — an unset row asks the catalogue.
            'vat_applicable' => $attributes['vat_applicable'] ?? null,
            // Only what the caller explicitly chose. Defaulting to the catalogue here is what
            // froze the rate for the life of the lease — a rise entered later never reached it.
            'vat_rate' => $attributes['vat_rate'] ?? null,
            // Null = advance, which is what every charge did before EG-30 — so a caller that says
            // nothing gets today's behaviour and only a deliberate `arrears` changes anything.
            'billing_timing' => $attributes['billing_timing'] ?? null,
            // Null, not false: null means the operator ruled on nothing and the lease's own
            // proration method stands, which is what every charge did before the flag existed.
            'prorate' => $attributes['prorate'] ?? null,
            // The row's own annual-increase rule (meeting 2026-09-02, point 24). Null reads as
            // `none` — a charge nobody ruled on steps nothing, which is what every charge but the
            // rent did before the column existed. A caller offering the property's proposal
            // (`ChargeEscalation::defaultModeFor()`) passes it explicitly.
            'escalation_mode' => $attributes['escalation_mode'] ?? null,
            'escalation_rate' => $attributes['escalation_rate'] ?? null,
            'escalation_amount' => $attributes['escalation_amount'] ?? null,
            // The FIRST row is dated to the lease commencement, not the effective date: a charge
            // that never existed should bill the lease's term, not only from today. This matches
            // what LeaseCreationService/LeaseRentChangeService did before schedules existed.
            //
            // `first_row_from_effective` opts out, and the distinction is real rather than a
            // convenience. That default assumes the charge conceptually applied all along and was
            // merely unrecorded — true of rent and the service charge. It is FALSE for anything
            // acquired mid-term: a parking bay taken on 1 March was not held in January, and
            // dating its first row to the commencement would back-charge months the tenant never
            // had it. Callers that let something say so.
            'start_date' => ($attributes['first_row_from_effective'] ?? false)
                ? $effectiveFrom->toDateString()
                : ($lease->commencement_date ?? $effectiveFrom->toDateString()),
            'is_active' => true,
        ]);
    }

    /**
     * Is this eve rung the step for `$anniversary` already, written under the pre-2026-09-13 snap?
     *
     * A projected or swept rung now starts ON its anniversary, so a rung that covers the EVE can
     * never be that anniversary's own step — except one written before 2026-09-13, which was
     * snapped to the 1st of the anniversary month and, in the window between that 1st and the
     * anniversary, has STARTED. Read as a base it would be stepped a second time: measured on the
     * soak box's anchor lease (rung 96,300 from 1 September, anniversary 15 September, today the
     * 13th), a re-true or the sweep would have minted 103,041 from the 15th. Such a rung is
     * ADOPTED as the step it is — by the projection's rent and charge walks and by the sweep's
     * charge steps — which is a no-op for every ladder written since, because none of theirs
     * matches the shape.
     *
     * One shape written since DOES match on its face: the rung that RESUMES the contract after a
     * relief window is the projected rung's own continuation (`escalation` origin, starting the
     * day after the window — the 1st) and carries the step the window covered, never the one
     * ahead. And a LEGACY ladder whose relief ended on the eve of the anniversary month had its
     * resumption re-priced to the snapped step — the same face, embodying the step ahead. The two
     * are told apart by the FIGURE the caller says the step would produce from what the sweep has
     * applied so far (`$ifApplied`): at the pointer's anniversary the lease COLUMN stepped once,
     * after it the walk's own contract figure (`$rent`, `$carry`) — a resumption that already
     * carries that figure is the step, one that does not is the contract resuming. Only the rent
     * and the service charge can carry a relief, so only they ever ask. (The corner an earlier
     * revision of this docblock left open — a relief ending on the eve of the anniversary month —
     * turned out not to exist: before the sweep such a resumption is a `manual` row at the
     * pre-step figure, the base the walk steps from; the real legacy corner was the SPANNING
     * window after its sweep. The column closes it where the column is kept; a service charge
     * kept on the schedule alone, with an empty column, still meets it — stated, not closed.)
     */
    public function stepAlreadyAppliedOn(BillableAgreement $lease, string $type, ?Charge $eve, CarbonImmutable $anniversary, ?float $ifApplied = null): bool
    {
        if ($eve === null
            || $eve->origin !== Charge::ORIGIN_ESCALATION
            || $eve->start_date === null) {
            return false;
        }

        $start = CarbonImmutable::instance($eve->start_date);

        if (! $start->gte(self::billingBoundary($anniversary)) || ! $start->lessThan($anniversary)) {
            return false;
        }

        if ($this->rowCovering($lease, $type, $start->subDay())?->origin !== Charge::ORIGIN_RELIEF) {
            return true;
        }

        return $ifApplied !== null && $this->sameMoney((float) $eve->amount, $ifApplied);
    }

    /**
     * Snap an effective date to the start of its billing month — the grain of a TYPED act.
     *
     * Change Rent, Add charge, a space change, a holdover conversion, a relief window, a bay let
     * or given back, a CAM estimate: each takes effect from the month its screen names ("The
     * month the new rent starts billing"), and each snaps its date here at its own door. Until
     * 2026-09-13 `setAmount()` snapped EVERY date it was handed, escalation anniversaries
     * included, because the planner billed one amount per charge type per month and a row
     * starting on the 15th would have left that month covered by two rows — so a lease
     * commencing on the 10th stepped its rent from the 1st of the anniversary month, nine days
     * early, at every anniversary (Trello gzwI17R0, High).
     *
     * **The anniversary step is NOT snapped.** The clause says "on each anniversary of the
     * commencement", so the rung starts ON the anniversary — the market's date-ranged charge
     * schedule (benchmark 01 §3.2, a rent step's effective date is "usually each anniversary")
     * — and `MonthlyBillingService::planInvoiceForLease()` bills each row for the days it is in
     * force inside the month, by the lease's own proration method, so the anniversary month
     * carries two lines: the days before at the old rent, the days from the anniversary at the
     * new. The typed acts keep the month grain their screens promise; widening any of them to
     * the day is now a one-line decision at that door, not a change to the money path.
     */
    public static function billingBoundary(CarbonImmutable $date): CarbonImmutable
    {
        return $date->startOfMonth();
    }

    /** Money equality to the cent — a 0.001 difference is not a rent change. */
    private function sameMoney(float $a, float $b): bool
    {
        return abs($a - $b) < 0.005;
    }
}
