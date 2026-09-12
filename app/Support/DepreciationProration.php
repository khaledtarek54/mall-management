<?php

namespace App\Support;

use App\Settings\AccountingSettings;
use Carbon\CarbonImmutable;

/**
 * How much of a month an asset acquired part-way through it is charged for (meeting 2026-09-02,
 * point 14) — SAP's period control, Odoo's "prorata", read by the ONE place that sizes a charge,
 * `DepreciationService::run()`.
 *
 * Two answers, and the market offers both: `full_month` charges the acquisition month whole
 * whatever the day (what this system always did, so it stays the default and no install's schedule
 * moves on deploy); `days` charges the days from the acquisition date to the month's end over the
 * month's length, and the balance the first month did not take falls into the last month of the
 * life through the clamp `run()` already applies — the schedule still sums to the depreciable base.
 *
 * A COMPANY-level setting, not per property or per asset: SAP fixes period control on the
 * depreciation key, one policy for the books, and two malls of one operator do not keep their
 * fixed-asset register on two conventions. Read at RUN time, never at schedule-definition time.
 */
final class DepreciationProration
{
    public const FULL_MONTH = 'full_month';

    public const DAYS = 'days';

    public const METHODS = [self::FULL_MONTH, self::DAYS];

    /** The convention in force, clamped to the two this class knows — a typo'd setting charges whole months. */
    public static function current(): string
    {
        $value = app(AccountingSettings::class)->depreciation_proration;

        return in_array($value, self::METHODS, true) ? $value : self::FULL_MONTH;
    }

    /**
     * The share of a month's charge the acquisition month takes — 1.0 whole, or the days from the
     * acquisition date to the month's end (inclusive) over the month's days under `days`. Any month
     * after the acquisition month is a whole month under either convention.
     */
    public static function firstMonthFraction(CarbonImmutable $acquiredOn, CarbonImmutable $month, ?string $method = null): float
    {
        $method ??= self::current();
        $month = $month->startOfMonth();

        if ($method !== self::DAYS || ! $acquiredOn->isSameMonth($month)) {
            return 1.0;
        }

        $daysInMonth = $month->daysInMonth;
        $daysHeld = $daysInMonth - $acquiredOn->day + 1;

        return round($daysHeld / $daysInMonth, 6);
    }
}
