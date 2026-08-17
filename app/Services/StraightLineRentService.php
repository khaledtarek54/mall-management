<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Lease;
use App\Settings\BillingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Recognise rent evenly over the term, however unevenly it is billed (story RA-02, EAS 49 / IFRS 16).
 *
 * **What it is for.** A five-year lease with a 7% annual step and three rent-free fit-out months
 * bills a different amount almost every year. The accounting standards say the LESSOR recognises
 * the total consideration evenly across the term regardless — so the P&L shows a flat rent while
 * the tenant is invoiced the contracted ladder, and the running difference sits in Deferred Rent.
 *
 * **This is only possible because of phase 1.** The whole contracted ladder exists as schedule rows
 * the day a lease is signed, so "total rent over the term" is a query rather than a projection. On
 * the old model — one mutable amount — the future was unknowable and straight-lining would have
 * meant inventing it.
 *
 * **It changes nothing a tenant sees.** No invoice, no VAT, no ETA payload is touched; the
 * adjustment is a journal entry between Deferred Rent and Rental Income. `StraightLineRentTest`
 * asserts invoices are byte-identical with the setting on and off, because "the books changed but
 * the bills did not" is the entire claim.
 *
 * **Forward-only.** The monthly amount is computed from the term as it stands, and a mid-term
 * amendment re-derives it for the months AHEAD. A closed period is never restated — the standards
 * expect a change in terms to be accounted for prospectively, and the posting-date guards would
 * refuse it anyway.
 */
class StraightLineRentService
{
    public function enabled(): bool
    {
        return (bool) app(BillingSettings::class)->straight_line_rent_enabled;
    }

    /**
     * The flat monthly rent this lease should RECOGNISE, and the inputs behind it.
     *
     * Null when the lease cannot be straight-lined — no term, or no rent schedule. Better to
     * recognise nothing than to average a term the system does not know the end of.
     *
     * @return array{monthly: float, total: float, months: int, from: CarbonImmutable, to: CarbonImmutable}|null
     */
    public function scheduleFor(Lease $lease): ?array
    {
        if (blank($lease->commencement_date) || blank($lease->expiry_date)) {
            return null;
        }

        $from = CarbonImmutable::instance($lease->commencement_date)->startOfMonth();
        $to = CarbonImmutable::instance($lease->expiry_date)->startOfMonth();

        if ($to->lessThan($from)) {
            return null;
        }

        $months = ($to->year - $from->year) * 12 + ($to->month - $from->month) + 1;

        /** @var Collection<int, Charge> $rows */
        $rows = $lease->charges()
            ->where('type', 'base_rent')
            ->where('is_active', true)
            ->where('frequency', '!=', 'one_time')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $total = 0.0;

        for ($i = 0; $i < $months; $i++) {
            $month = $from->addMonths($i);

            // Rent-free months count in the DENOMINATOR but contribute nothing to the numerator:
            // that is precisely what makes an abatement spread across the term instead of landing
            // entirely in the months it was granted. Reading it from the same predicate the billing
            // engine uses means the two can never disagree about which months are free.
            if ($lease->periodInFitOut($month->endOfMonth())
                || in_array('base_rent', $lease->abatedChargeTypesFor($month->endOfMonth()), true)) {
                continue;
            }

            $total += (float) (ChargeScheduleService::pickInForce($rows, $month)?->amount ?? 0);
        }

        if ($total <= 0) {
            return null;
        }

        return [
            'monthly' => round($total / $months, 2),
            'total' => round($total, 2),
            'months' => $months,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * What this lease was BILLED as base rent for a month — the figure the adjustment nets against.
     *
     * Read from the schedule, not from invoices: the adjustment must be computable for a month
     * whose invoice has not been raised yet, and a cancelled or re-issued invoice must not move
     * revenue recognition. The schedule is what the lease says it owes.
     */
    public function billedFor(Lease $lease, CarbonImmutable $month): float
    {
        $month = $month->startOfMonth();

        if ($lease->periodInFitOut($month->endOfMonth())
            || in_array('base_rent', $lease->abatedChargeTypesFor($month->endOfMonth()), true)) {
            return 0.0;
        }

        /** @var Collection<int, Charge> $rows */
        $rows = $lease->charges()
            ->where('type', 'base_rent')
            ->where('is_active', true)
            ->where('frequency', '!=', 'one_time')
            ->get();

        return round((float) (ChargeScheduleService::pickInForce($rows, $month)?->amount ?? 0), 2);
    }

    /**
     * The adjustment for one month: recognised less billed.
     *
     * Positive → recognise MORE than billed (Dr Deferred Rent / Cr Rental Income), the normal shape
     * early in a stepped lease. Negative → the reverse, as the ladder overtakes the average.
     * Null when the lease cannot be straight-lined, or the month falls outside its term.
     */
    public function adjustmentFor(Lease $lease, CarbonImmutable $month): ?float
    {
        $schedule = $this->scheduleFor($lease);

        if ($schedule === null) {
            return null;
        }

        $month = $month->startOfMonth();

        if ($month->lessThan($schedule['from']) || $month->greaterThan($schedule['to'])) {
            return null;
        }

        return round($schedule['monthly'] - $this->billedFor($lease, $month), 2);
    }
}
