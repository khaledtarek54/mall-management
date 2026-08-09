<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Lease;
use Carbon\CarbonImmutable;

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
    public function setAmount(
        Lease $lease,
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
            'lease_id' => $lease->id,
            'name' => $attributes['name'] ?? $current->name,
            'type' => $type,
            'origin' => $origin,
            'amount' => $amount,
            'currency' => $lease->currency ?? 'EGP',
            'frequency' => $attributes['frequency'] ?? $current->frequency,
            'vat_applicable' => $attributes['vat_applicable'] ?? $current->vat_applicable,
            'vat_rate' => $attributes['vat_rate'] ?? $current->vat_rate,
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
     * Only `fixed_percent` is projected: CPI has no index feed, and inventing the number here
     * would be inventing data — the same reason the sweep skips it.
     *
     * The marketing levy is projected in lock-step because it is a percentage of base rent; a
     * complete rent schedule beside a single-row levy would bill a future month's rent correctly
     * next to a levy derived from year one.
     *
     * @return int rows created (rent + levy)
     */
    public function projectTermEscalations(Lease $lease): int
    {
        if ($lease->escalation_type !== 'fixed_percent'
            || (float) $lease->escalation_rate <= 0
            || blank($lease->commencement_date)
            || blank($lease->expiry_date)) {
            return 0;
        }

        $rate = (float) $lease->escalation_rate;
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
            : CarbonImmutable::instance($lease->commencement_date)->addYear();

        if ($rent <= 0) {
            return 0;
        }

        $levyService = app(MarketingLevyService::class);
        $levyRate = $lease->has_marketing_levy ? $levyService->ratePercent($lease) : 0.0;

        $created = 0;

        // Anniversaries inside the term. The first step is one year after commencement; the last
        // is whichever anniversary still starts before expiry — a lease ending mid-year gets no
        // step it will never reach.
        for ($year = 0; ; $year++) {
            $effective = self::billingBoundary($firstStep->addYears($year));

            if ($effective->greaterThan($expiry)) {
                break;
            }

            $rent = round($rent * (1 + $rate / 100), 2);

            if ($this->setAmount($lease, 'base_rent', $rent, $effective, [
                'name' => 'Base Rent',
                'vat_applicable' => false,
                'vat_rate' => 0,
            ], Charge::ORIGIN_ESCALATION)) {
                $created++;
            }

            if ($levyRate > 0 && $this->setAmount($lease, 'marketing', round($rent * $levyRate / 100, 2), $effective, [
                'name' => 'Marketing Levy',
                'frequency' => 'monthly',
                'vat_applicable' => false,
                'vat_rate' => 0,
            ], Charge::ORIGIN_LEVY)) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * The schedule for a charge type, oldest first — the operator-facing view of "what has this
     * lease been billed, and what will it be billed".
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Charge>
     */
    public function scheduleFor(Lease $lease, string $type)
    {
        return Charge::query()
            ->where('lease_id', $lease->id)
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
    public function rowInForce(Lease $lease, string $type, CarbonImmutable $on): ?Charge
    {
        // Always a FRESH read: this runs inside setAmount()'s transaction, right after rows may
        // have been written, so a cached relation would hand back a stale answer.
        return self::pickInForce(
            Charge::query()->where('lease_id', $lease->id)->where('type', $type)->get(),
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
        Lease $lease,
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
            'lease_id' => $lease->id,
            'name' => $attributes['name'] ?? ucfirst(str_replace('_', ' ', $type)),
            'type' => $type,
            'origin' => $origin,
            'amount' => $amount,
            'currency' => $lease->currency ?? 'EGP',
            'frequency' => $attributes['frequency'] ?? 'monthly',
            'vat_applicable' => $attributes['vat_applicable'] ?? false,
            'vat_rate' => $attributes['vat_rate'] ?? 0,
            // The FIRST row is dated to the lease commencement, not the effective date: a charge
            // that never existed should bill the lease's term, not only from today. This matches
            // what LeaseCreationService/LeaseRentChangeService did before schedules existed.
            'start_date' => $lease->commencement_date ?? $effectiveFrom->toDateString(),
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
