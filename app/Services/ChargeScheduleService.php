<?php

namespace App\Services;

use App\Contracts\BillableAgreement;
use App\Models\Charge;
use App\Models\Lease;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
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
     * @param  array<string, mixed>  $attributes  name / vat_applicable / vat_rate / frequency for a new row
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
        $effectiveFrom = self::billingBoundary($effectiveFrom);

        $current = $this->rowInForce($lease, $type, $effectiveFrom);

        if ($current === null) {
            return $this->openFirstRow($lease, $type, $amount, $effectiveFrom, $attributes, $origin);
        }

        if ($this->sameMoney((float) $current->amount, $amount)) {
            return $current;
        }

        // A row that has not started yet has billed nothing — correct it rather than closing it
        // the day before it began, which would leave an unbillable stub in the schedule.
        if ($current->start_date && CarbonImmutable::instance($current->start_date)->gte($effectiveFrom)) {
            $current->update(['amount' => $amount, 'origin' => $origin]);

            return $current;
        }

        // Past this point the row in force provably started BEFORE the effective date (the branch
        // above returned otherwise), so closing it the day before cannot produce a backwards or
        // zero-length range.
        $inheritedEnd = $current->end_date;
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
            // INHERITED, like every other override on this row — and it was the one EG-30 forgot.
            // A rent change, an escalation step and a CAM estimate all come through here. A RELIEF
            // and a RENEWAL do NOT — `overlayWindow()` below and `LeaseRenewalService` build their
            // rows directly, and this comment used to claim otherwise, which is precisely why both
            // of them were still dropping these terms a fortnight later. Dropping it reverted an
            // arrears service
            // charge to ADVANCE on the next rung, so the tenant would be billed September's
            // service in September having been billed August's in September the month before —
            // one month charged twice, with the schedule looking entirely ordinary.
            'billing_timing' => $attributes['billing_timing'] ?? $current->billing_timing,
            // Carried onto the next rung, like every other term of the row. A signage licence that
            // does not prorate must not start prorating because its amount was revised — and this
            // method builds an EXPLICIT attribute list, which is exactly how `billing_timing` came
            // to be rendered on a form and thrown away on save.
            'prorate' => $attributes['prorate'] ?? $current->prorate,
            'start_date' => $effectiveFrom->toDateString(),
            // Inherit the boundary, so closing a bounded schedule doesn't quietly make it open.
            'end_date' => $inheritedEnd,
            'is_active' => true,
        ]);
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
     * **`fixed_percent` AND `fixed_amount` are projected; only CPI is not.** CPI has no index feed
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
     * @return int rows created (rent + levy)
     */
    public function projectTermEscalations(Lease $lease): int
    {
        // Read the type as a plain string: `escalation_type` was created as a DB-level
        // enum('none','fixed_percent','cpi') in 2024, and static analysis still derives the
        // attribute type from that migration rather than the `->change()` that made it a varchar —
        // so a direct comparison against `fixed_amount` reads as "always false" (the same trap
        // annotated in RentEscalationService).
        $type = (string) $lease->escalation_type;

        $byPercent = $type === 'fixed_percent' && (float) $lease->escalation_rate > 0;
        $byAmount = $type === 'fixed_amount' && (float) $lease->escalation_amount > 0;

        if ((! $byPercent && ! $byAmount)
            || blank($lease->commencement_date)
            || blank($lease->expiry_date)) {
            return 0;
        }

        $rate = (float) $lease->escalation_rate;
        $step = round((float) $lease->escalation_amount, 2);
        $expiry = CarbonImmutable::instance($lease->expiry_date);
        $rent = (float) $lease->base_rent_monthly;

        // Anchor on the lease's OWN next anniversary, not on commencement + N.
        //
        // For a lease being created the two are identical (Lease::creating arms
        // next_escalation_date = commencement + 1yr). They diverge for a lease already part-way
        // through its term — `base_rent_monthly` is the rent reached so far and
        // `next_escalation_date` is the next step due — which is exactly the case a backfill of
        // the existing portfolio hits. Projecting from commencement there would re-apply years
        // that have already been applied and date every step wrongly.
        $firstStep = $lease->next_escalation_date
            ? CarbonImmutable::instance($lease->next_escalation_date)
            : $lease->escalationDateAfter(CarbonImmutable::instance($lease->commencement_date));

        if ($rent <= 0) {
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
            $effective = self::billingBoundary($stepDate);

            if ($effective->greaterThan($expiry)) {
                break;
            }

            $rent = $byAmount
                ? round($rent + $step, 2)
                : round($rent * (1 + $rate / 100), 2);

            if ($this->setAmount($lease, 'base_rent', $rent, $effective, [
                'name' => 'Base Rent',
            ], Charge::ORIGIN_ESCALATION)) {
                $created++;
            }

            if ($levyRate > 0 && $this->setAmount($lease, 'marketing', round($rent * $levyRate / 100, 2), $effective, [
                'name' => 'Marketing Levy',
                'frequency' => 'monthly',
            ], Charge::ORIGIN_LEVY)) {
                $created++;
            }

            $stepDate = $lease->escalationDateAfter($stepDate);
        }

        return $created;
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
        // The window ends at a MONTH boundary too: the engine bills one amount per type per month,
        // so a window ending on the 10th would leave that month covered by both the relief row and
        // the resumed one — the ambiguity `assertScheduleUnambiguous()` refuses.
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
                // The row's own TERMS, carried like everything else above. `setAmount()`'s comment
                // claims "every successor comes through here", and relief is the one that does not —
                // it builds its rows directly. Dropping `billing_timing` reverts an arrears-billed
                // service charge to advance for the relief segments only, so the crossover months
                // bill twice or not at all; dropping `prorate` makes a flat licence start prorating
                // the month relief begins and ends.
                'billing_timing' => $row->billing_timing,
                'prorate' => $row->prorate,
                'start_date' => $step['segment_start']->toDateString(),
                'end_date' => $step['segment_end']->toDateString(),
                'is_active' => true,
            ]);
        }

        $resumed = null;

        if ($resumeAfter !== null) {
            /** @var Charge $row */
            $row = $resumeAfter['row'];

            $resumed = Charge::create([
                ...$lease->invoiceLinkAttributes(),
                'name' => $row->name,
                'type' => $type,
                'origin' => $origin,
                'amount' => $resumeAfter['amount'],
                'currency' => $lease->currency ?? 'EGP',
                'frequency' => $row->frequency,
                'vat_applicable' => $row->vat_applicable,
                'vat_rate' => $row->vat_rate,
                // Same terms as the row this RESUMES — the whole point of the resumed row is that
                // the contract goes back to what it was before the relief window.
                'billing_timing' => $row->billing_timing,
                'prorate' => $row->prorate,
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

                $current->update($startsLater
                    ? ['is_active' => false]
                    : ['end_date' => $from->subDay()->toDateString(), 'is_active' => false]);

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
        $rows = collect($charges)->where('is_active', true);

        $covering = $rows
            ->filter(fn (Charge $c) => (blank($c->start_date) || CarbonImmutable::instance($c->start_date)->lte($on))
                && (blank($c->end_date) || CarbonImmutable::instance($c->end_date)->gte($on)))
            ->sortBy([
                fn (Charge $a, Charge $b) => ($a->start_date?->timestamp ?? 0) <=> ($b->start_date?->timestamp ?? 0),
                fn (Charge $a, Charge $b) => $a->id <=> $b->id,
            ]);

        if ($covering->isNotEmpty()) {
            return $covering->last();
        }

        // Nothing covers the date — fall back to the latest active row, which is what keeps a
        // pre-schedule lease (one open-ended row) and a lease whose schedule has run out behaving
        // sensibly instead of reading as "no rent".
        return $rows->sortBy([
            fn (Charge $a, Charge $b) => ($a->start_date?->timestamp ?? 0) <=> ($b->start_date?->timestamp ?? 0),
            fn (Charge $a, Charge $b) => $a->id <=> $b->id,
        ])->last();
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
     * Snap an effective date to the start of its billing month.
     *
     * The billing engine bills **one amount per charge type per month**, so a row that starts on
     * the 15th would leave that month covered by two rows — genuinely ambiguous, and exactly what
     * `MonthlyBillingService::assertScheduleUnambiguous()` refuses. Snapping means a schedule
     * change never splits a month.
     *
     * It also **reproduces the old behaviour exactly**, which is the point: overwriting an amount
     * mid-month always billed that whole month at the new rent. A lease whose escalation
     * anniversary falls on the 15th of April therefore bills all of April at the new rent, as it
     * always has — the difference is that March's rent is now still readable.
     *
     * Mid-month proration of a rent change is a real capability and deliberately NOT in this
     * increment: it needs the billing engine to split a period, which is a change to the money
     * path rather than to the schedule.
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
