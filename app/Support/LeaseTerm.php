<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The one rule relating a lease's commencement, its term in months, and its expiry.
 *
 * **`expiry = commencement + term − 1 day`.** A twelve-month lease from 1 January ends on
 * 31 December, not on 1 January: the last day of the term is a day the tenant occupies, and an
 * expiry set a day late bills a thirteenth month on the anniversary. That rule was written out
 * twice — `LeaseCreationService` and `LeaseRenewalService` — and nowhere else, which is why the
 * FORM let the three fields disagree: `commencement_date`, `term_months` and `expiry_date` were
 * three independent inputs, so a lease could be saved as "36 months" spanning twelve.
 *
 * It is not decoration when they disagree. `term_months` is logged on the lease, copied by renewal,
 * and read by the option-exercise service, so the disagreement propagates into the next contract.
 *
 * ## Month ends do not overflow
 *
 * `addMonths()` — which both services used — **overflows**: 31 August plus six months is
 * "31 February", which Carbon resolves to 3 March, so the lease expired on **2 March instead of
 * 27 February**. Three days outside the agreed term, billed, on any lease commencing on a day its
 * end month does not have. `addMonthsNoOverflow()` clamps to the last day of the target month,
 * which is what "six months from the 31st" means to the people signing.
 *
 * This only changes what is DERIVED from now on. Every existing lease carries its expiry as a
 * stored column and is untouched — which is the right direction: a signed contract's end date is
 * not something a code change may move.
 *
 * ## The inverse refuses to round
 *
 * {@see monthsBetween()} returns null unless the range is a WHOLE number of months. An expiry of
 * 30 December on a January commencement is eleven months and twenty-nine days; answering "11" would
 * quietly restate a negotiated end date as a tidy term nobody agreed. A bespoke expiry is a real
 * thing — a lease aligned to a mall's financial year, or to another tenant's fit-out — and the
 * honest response is to leave the term alone rather than to make the pair agree by force.
 */
class LeaseTerm
{
    /** The expiry a term of `$months` from `$commencement` ends on, or null if either is missing. */
    public static function expiryFrom(mixed $commencement, mixed $months): ?string
    {
        $start = self::date($commencement);
        $months = is_numeric($months) ? (int) $months : null;

        if ($start === null || $months === null || $months < 1) {
            return null;
        }

        return $start->addMonthsNoOverflow($months)->subDay()->toDateString();
    }

    /**
     * The whole number of months between `$commencement` and `$expiry`, or null when it is not one.
     *
     * Null is the answer that keeps a negotiated end date intact — see the class docblock.
     */
    public static function monthsBetween(mixed $commencement, mixed $expiry): ?int
    {
        $start = self::date($commencement);
        $end = self::date($expiry);

        if ($start === null || $end === null || $end <= $start) {
            return null;
        }

        // Defined BY the forward rule rather than alongside it: estimate the term, then accept it
        // only if `expiryFrom()` reproduces this exact date. Anything else would be a second
        // opinion about the same relationship, and the two would drift at month ends — which is
        // precisely where `diffInMonths()` and the clamped forward rule disagree (31 January to
        // 27 February is one month by the contract and zero by the calendar difference).
        $estimate = (int) $start->diffInMonths($end->addDay());

        foreach ([$estimate, $estimate + 1, $estimate - 1] as $candidate) {
            if ($candidate >= 1 && self::expiryFrom($start->toDateString(), $candidate) === $end->toDateString()) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The whole months a range COVERS, floored — a descriptor, not an assertion of equality.
     *
     * {@see monthsBetween()} answers "is this range exactly N months?" and returns null when it is
     * not, which is right where there is an existing term to leave alone: the form must not restate
     * a negotiated end date as a tidy number.
     *
     * An import has no such luxury. `leases.term_months` is NOT NULL, and writing null there is the
     * failure mode this codebase names explicitly — an optional blank field reaching a NOT-NULL
     * column. So when a CSV carries a bespoke end date and no term, something has to go in the
     * column, and the honest something is how many whole months the range covers. The EXPIRY is
     * stored exactly either way, and it is the contract date; the term is a description of it.
     */
    public static function monthsSpanning(mixed $commencement, mixed $expiry): ?int
    {
        $exact = self::monthsBetween($commencement, $expiry);

        if ($exact !== null) {
            return $exact;
        }

        $start = self::date($commencement);
        $end = self::date($expiry);

        if ($start === null || $end === null || $end <= $start) {
            return null;
        }

        // Walk down from the calendar estimate until the derived expiry no longer overshoots.
        for ($months = (int) $start->diffInMonths($end->addDay()) + 1; $months >= 1; $months--) {
            $derived = self::expiryFrom($start->toDateString(), $months);

            if ($derived !== null && $derived <= $end->toDateString()) {
                return $months;
            }
        }

        return null;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            // A half-typed date from a live form field is not an error worth throwing over; the
            // caller simply has nothing to derive from yet.
            return null;
        }
    }
}
