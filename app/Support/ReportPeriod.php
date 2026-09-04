<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * A scheduled report reports on a NEW period of the SAME SHAPE — never on a different shape.
 *
 * ## What this replaced, and why the obvious version was worse than the bug
 *
 * A `SavedReport` snapshots every declared parameter, the delivery re-applies them, and a report
 * page derives its period from `now()` in `mount()`. So the frozen value overwrote the fresh one:
 * "send every month" emailed September's figures in October, in November, and for ever.
 *
 * The first repair simply DROPPED the period parameters and let each page's own `mount()` default
 * stand. That is right for `asOf` and it is **wrong for `year` + `period`**, because a null `period`
 * does not mean "this month" on `ScopesLedgerReport` — it means *the whole fiscal year*. Measured:
 * a monthly VAT return saved for March was delivered as `vat-return-2026.csv` carrying the year's
 * cumulative `net_payable`, on a document Egypt files monthly, with no period line in the rows at
 * all. A stale return is the wrong month; that is the wrong AMOUNT, and it looks fresh — which is
 * what makes it likelier to be filed. Form 41 went from a quarter to a year; the balance sheet's
 * *as at* became 31 December, a future date on every delivery until December.
 *
 * So the period is REWRITTEN in its own shape rather than removed:
 *
 *  - **`asOf`** — today. A point has no length to preserve.
 *  - **`from` + `to`** — the same SPAN, ending today. Dropping them reset a one-quarter vendor
 *    scorecard to the page's hardcoded rolling twelve months: roughly four times the volume, which
 *    is the operator's shape going out with their moment.
 *  - **`year` + `period`** — the latest COMPLETE period of the shape that was saved. A month-shaped
 *    period becomes last month, a quarter-shaped one last quarter, and a null one (the whole year)
 *    stays null on the current year. "The month just ended" is what a monthly statutory return is
 *    actually filed for, and it is the one thing no page's `mount()` can produce.
 *
 * An unrecognised shape is left ALONE. Better a stale period the recipient can spot than a
 * confidently rewritten one in a shape this class did not understand.
 *
 * @see ReportCatalogue::REPORTING_PERIOD which parameters are a period, per page
 */
final class ReportPeriod
{
    /**
     * The saved parameters with the reporting period moved to now, in its own shape.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public static function advance(string $page, array $parameters, ?CarbonImmutable $on = null): array
    {
        $names = ReportCatalogue::reportingPeriodOf($page);

        if ($names === []) {
            return $parameters;
        }

        $on ??= CarbonImmutable::now();

        if (in_array('asOf', $names, true)) {
            $parameters['asOf'] = $on->toDateString();
        }

        if (in_array('from', $names, true) && in_array('to', $names, true)) {
            $parameters = self::advanceSpan($parameters, $on);
        }

        if (in_array('period', $names, true)) {
            $parameters = self::advanceLedgerPeriod($parameters, $on);
        } elseif (in_array('year', $names, true)) {
            // `TaxDepreciation` — a year and nothing finer. The latest COMPLETE year, for the same
            // reason a month-shaped period becomes last month: a depreciation schedule for a year
            // still running is not a document anybody files.
            $parameters['year'] = $on->year - 1;
        }

        return $parameters;
    }

    /**
     * **The same two dates, in order.**
     *
     * An inverted `from`/`to` is a typo, not a question — and every range report answers a typo with
     * a confident zero rather than an error. Measured at HEAD 2026-09-04: with `from` after `to`,
     * `ReportService::weeklySpend()` seeds no weeks at all (its cursor loop never runs) and both
     * `whereBetween` clauses match nothing, so `WeeklySpend::getSubheading()` renders
     * "EGP 0.00 · EGP 0.00 · EGP 0.00" — an empty table under three figures that read as a finding,
     * on the screen and in the emailed CSV alike.
     *
     * Swapping is what the operator meant: the same two dates, read the way every date-range control
     * reads them, and the reports return the ordered pair in their own payload so nothing prints a
     * window it did not use. It is not a money decision — it decides which rows are read, never what
     * they are worth.
     *
     * A HALF-stated window is left alone, the same rule {@see self::advanceSpan()} already applies to
     * it: there is no order to fix, and each report's own default is a better answer than half of one.
     *
     * `ReportFilters::from()`/`to()` bound each other so the panel cannot state one in the first
     * place. This is the guarantee underneath, at the chokepoint a saved view, a `?from=` in the URL
     * and a scheduled delivery all pass through.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public static function orderedSpan(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        if ($from === null || $to === null) {
            return [$from, $to];
        }

        return $from->greaterThan($to) ? [$to, $from] : [$from, $to];
    }

    /**
     * Keep the window's LENGTH and move its end to today.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private static function advanceSpan(array $parameters, CarbonImmutable $on): array
    {
        $from = self::parse($parameters['from'] ?? null);
        $to = self::parse($parameters['to'] ?? null);

        // A half-stated window has no length to preserve, so there is nothing to move. Left alone
        // rather than guessed: `mount()`'s own default is a better answer than half of one.
        if ($from === null || $to === null || $from->greaterThan($to)) {
            return $parameters;
        }

        $days = $from->diffInDays($to);

        $parameters['to'] = $on->toDateString();
        $parameters['from'] = $on->subDays($days)->toDateString();

        return $parameters;
    }

    /**
     * The latest COMPLETE period of the shape that was saved.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private static function advanceLedgerPeriod(array $parameters, CarbonImmutable $on): array
    {
        $period = $parameters['period'] ?? null;

        // Null is the WHOLE YEAR, and that is a shape too — it moves to the current year rather
        // than becoming a month.
        if (! is_string($period) || $period === '') {
            $parameters['year'] = $on->year;
            $parameters['period'] = null;

            return $parameters;
        }

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1) {
            $month = $on->subMonthNoOverflow()->startOfMonth();

            // The FISCAL year the month falls in, never `$month->year`. On an April year February
            // 2027 belongs to FY2026, and naming 2027 sends the report to a year whose own picker
            // does not offer that month.
            $parameters['year'] = self::fiscalYearOf($month);
            $parameters['period'] = $month->format('Y-m');

            return $parameters;
        }

        if (preg_match('/^\d{4}-Q([1-4])$/', $period) === 1) {
            // **A quarter here is a quarter of the FISCAL year, and Carbon's is a calendar one.**
            // `WithholdingTaxReturn::periodOptions()` builds `YYYY-Qn` by stepping three months at a
            // time from `fiscalYearStart()`, so on an April year Q1 is Apr–Jun. Reading
            // `$on->subQuarterNoOverflow()->startOfQuarter()->quarter` gave the calendar answer:
            // measured on 15 Aug 2026 with an April year it produced `2026-Q2`, which that page
            // renders as **Jul–Sep 2026 — the quarter still running**. A scheduled Form 41 would go
            // out on a partial quarter, which is a filing position, not a stale report.
            $month = $on->startOfMonth();
            $start = self::fiscalYearStartOn($month);

            // Whole quarters elapsed since this fiscal year opened, then step back one so the
            // period delivered is the last COMPLETE one.
            $index = intdiv($start->diffInMonths($month), 3);
            $target = $start->addMonths(($index - 1) * 3);

            $parameters['year'] = self::fiscalYearOf($target);
            $parameters['period'] = $parameters['year'].'-Q'.(intdiv(
                self::fiscalYearStartOn($target)->diffInMonths($target), 3
            ) + 1);

            return $parameters;
        }

        // A shape this class does not understand. Left exactly as saved — a stale period a
        // recipient can spot beats a confidently rewritten one in a shape nobody here parsed.
        return $parameters;
    }

    private static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return rescue(fn (): CarbonImmutable => CarbonImmutable::parse($value)->startOfDay(), null, false);
    }

    /**
     * The FISCAL year a date belongs to — the number the report pages use as `$year`.
     *
     * On a calendar year this is `$on->year` and the two never diverge, which is exactly why the
     * distinction is easy to lose: every month of 2026 is in FY2026 until the operator sets
     * `fiscal_year_start_month`, and then three of them are not.
     */
    private static function fiscalYearOf(CarbonImmutable $on): int
    {
        return $on->month >= FiscalYearStart::month() ? $on->year : $on->year - 1;
    }

    /** The first day of the fiscal year that CONTAINS `$on`. */
    private static function fiscalYearStartOn(CarbonImmutable $on): CarbonImmutable
    {
        return CarbonImmutable::create(self::fiscalYearOf($on), FiscalYearStart::month(), 1)->startOfDay();
    }
}
