<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * How a PARTIAL month is priced — the four methods a lease can state (EG-29 / M-1).
 *
 * Proration was one hardcoded line: a partial month billed its days ÷ that month's own length. That
 * is one of four methods Yardi Voyager ships, and it is chosen on the property with a lease
 * override, because **leases say different things**. A clause reading "one thirtieth of the monthly
 * rent per day" is billed wrong in the seven months that do not have thirty days — under-billed in
 * a 31-day month, over-billed in February — on every move-in, move-out, rent commencement and final
 * cycle.
 *
 * ## A FULL month is always exactly one month
 *
 * The divisor applies to a partial month only. Without that rule {@see THIRTY_DAY} bills 31/30 of a
 * month every August and {@see YEAR_365} bills 31 × 12 ÷ 365 — both more than a month's rent for a
 * month the tenant occupied normally, which is not what any of these methods mean. Yardi behaves
 * the same way: the daily rate exists to price the stub, not to re-price the month.
 *
 * ## The methods
 *
 * - {@see ACTUAL} — days ÷ that month's own length. **The default, and what this system has always
 *   done**, so nothing moves on deploy.
 * - {@see THIRTY_DAY} — days ÷ 30. The "1/30th per day" clause; 30/360 in accounting terms.
 * - {@see YEAR_365} — days × 12 ÷ 365. An annual rent divided by the days in a year, expressed as a
 *   share of a month so it composes with everything else here.
 * - {@see WHOLE_MONTH} — any occupancy in the month bills the whole month. Blunt, and some leases
 *   genuinely say it.
 *
 * ## Why this returns a MONTH SHARE rather than a daily rate
 *
 * Every caller multiplies a MONTHLY amount — `MonthlyBillingService::monthsCovered()` is the one
 * definition of "how much of a period does this agreement run", and the termination credit reads
 * the same rule so a credit cannot disagree with the invoice it credits. Returning a share keeps
 * all four methods interchangeable at that one seam.
 */
final class ProrationMethod
{
    /** Days ÷ that month's own length. The default — what this system has always done. */
    public const ACTUAL = 'actual';

    /** Days ÷ 30 — the "one thirtieth per day" clause (30/360). */
    public const THIRTY_DAY = 'thirty_day';

    /** Days × 12 ÷ 365 — an annual rent spread over the days of a year. */
    public const YEAR_365 = 'year_365';

    /** Any occupancy in the month bills the whole month. */
    public const WHOLE_MONTH = 'whole_month';

    /** @var array<int, string> */
    public const METHODS = [self::ACTUAL, self::THIRTY_DAY, self::YEAR_365, self::WHOLE_MONTH];

    /** What an install does when nobody has chosen: exactly what it did before EG-29. */
    public const DEFAULT = self::ACTUAL;

    /**
     * The share of ONE month that `$days` of occupancy earns.
     *
     * `$isFullMonth` is passed rather than inferred from the day count: a month is fully covered
     * when the window spans it end to end, which the caller already knows, and inferring it from
     * `$days === $daysInMonth` would quietly treat a 28-day stub in a 28-day February as a full
     * month it might not be.
     */
    public static function shareOfMonth(string $method, int $days, int $daysInMonth, bool $isFullMonth): float
    {
        if ($days <= 0) {
            return 0.0;
        }

        // A full month is a full month under every method. The divisor prices the STUB; without
        // this, 30/360 bills 31/30 of a month every August and 365 bills 31 × 12 ÷ 365 — more than
        // a month's rent for a month the tenant occupied normally.
        if ($isFullMonth) {
            return 1.0;
        }

        return match ($method) {
            self::WHOLE_MONTH => 1.0,
            self::THIRTY_DAY => $days / 30,
            self::YEAR_365 => $days * 12 / 365,
            default => $days / max($daysInMonth, 1),
        };
    }

    /** Is this one of the four? Used where a stored value could be anything. */
    public static function isKnown(?string $method): bool
    {
        return $method !== null && in_array($method, self::METHODS, true);
    }

    /**
     * The method to bill by, falling back the way every other lease term does.
     *
     * A stored value that is not one of the four falls back rather than throwing. Proration runs
     * inside the monthly billing run; a hand-edited row must not stop a night's invoicing.
     */
    public static function resolve(?string $leaseMethod, ?int $assetId = null): string
    {
        if (self::isKnown($leaseMethod)) {
            return $leaseMethod;
        }

        $configured = PropertySettings::get('billing.proration_method', $assetId);

        return self::isKnown(is_string($configured) ? $configured : null)
            ? $configured
            : self::DEFAULT;
    }

    /**
     * Whether a window covers a whole calendar month, end to end.
     *
     * Compared on DAY boundaries, exactly as `monthsCovered()` counts its days. Comparing raw
     * timestamps looks equivalent and is not: `endOfMonth()` is 23:59:59 while a period end that
     * has been through `startOfDay()` — which several billing paths do — is 00:00:00 on the same
     * date, so the last day of the month read as "not covered" and a 31-day August billed 31/30 of
     * a month under the thirty-day method. Intermittent, because it depended on which caller built
     * the date.
     */
    public static function coversWholeMonth(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $month): bool
    {
        return $from->startOfDay()->lessThanOrEqualTo($month->startOfMonth()->startOfDay())
            && $to->startOfDay()->greaterThanOrEqualTo($month->endOfMonth()->startOfDay());
    }
}
