<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * **How far an OPERATOR may reach when billing by hand.**
 *
 * The last twelve months, plus the next one: rent bills in advance so the upcoming month must be
 * reachable, a quarterly or annual cycle is raised in its own start month so nothing needs more
 * than that, and a year back covers every backfill an operator legitimately does.
 *
 * **Why this is a class and not a method on the screen that first needed it.** The window lived
 * privately on `BillingRunPreview`, so the Billing Run Preview offered September while the lease's
 * own "Generate Invoice" accepted any month at all — the same deal billable four months early from
 * one screen and refused a preview from the other. One rule with two homes is the defect shape this
 * module keeps producing; it has one home now, and both screens read it.
 *
 * **It bounds the OPERATOR, not the engine.** `MonthlyBillingService` stays unclamped on purpose:
 * the scheduled run, `billing:run --period=`, a data backfill and the test suite all bill periods
 * chosen deliberately by someone who is not clicking a button, and a guard in the service would
 * refuse those too. The line is "typed into a form", which is exactly where a mis-key becomes a
 * receivable nobody meant to raise.
 */
class BillingWindow
{
    /** How far back a manual run may reach. */
    public const MONTHS_BACK = 12;

    /** …and how far forward. One: the month rent is raised in advance for. */
    public const MONTHS_AHEAD = 1;

    /** The earliest billable month, normalised to its first day. */
    public static function earliest(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfMonth()->subMonths(self::MONTHS_BACK);
    }

    /** The latest billable month, normalised to its first day. */
    public static function latest(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfMonth()->addMonths(self::MONTHS_AHEAD);
    }

    /** May an operator raise an invoice for this period by hand? */
    public static function allows(CarbonImmutable $period): bool
    {
        $month = $period->startOfMonth();

        return ! $month->lessThan(self::earliest()) && ! $month->greaterThan(self::latest());
    }

    /**
     * The window as a picker, newest first — `Y-m` keyed, month names in the reader's language.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        $cursor = self::latest();
        $earliest = self::earliest();

        while (! $cursor->lessThan($earliest)) {
            $options[$cursor->format('Y-m')] = $cursor->locale(app()->getLocale())->isoFormat('MMMM YYYY');
            $cursor = $cursor->subMonth();
        }

        return $options;
    }
}
