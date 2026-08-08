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
        $covering = Charge::query()
            ->where('lease_id', $lease->id)
            ->where('type', $type)
            ->effectiveOn($on)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first();

        if ($covering) {
            return $covering;
        }

        return Charge::query()
            ->where('lease_id', $lease->id)
            ->where('type', $type)
            ->where('is_active', true)
            ->orderByRaw('start_date is null desc')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
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
