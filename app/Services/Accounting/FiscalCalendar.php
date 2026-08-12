<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Support\FiscalYearStart;
use Illuminate\Support\Carbon;

/**
 * Opens fiscal years and their 12 monthly periods (idempotent). Reused by the seeder, tests, and
 * the period-management UI.
 *
 * **The start month is configured, not assumed.** This used to hardcode January and say so — "a
 * fiscal year starting in another month is a future option" — while the reports already read
 * `fiscal_years.starts_on` and honoured whatever they found. So the data model supported a July
 * year all along and nothing could create one, which left an entity on a July–June year running
 * every statement and close on somebody else's calendar.
 *
 * A year is named for the calendar year it STARTS in: `ensureYear(2026)` with a July start is
 * 1 July 2026 – 30 June 2027. See `App\Support\FiscalYearStart`, which also holds the rule about
 * changing the month once entries are posted.
 */
class FiscalCalendar
{
    public function ensureYear(int $year): FiscalYear
    {
        $start = Carbon::create($year, FiscalYearStart::month(), 1)->startOfDay();

        // A year from the start, less a day — NOT `->endOfYear()`, which would end a July year on
        // 31 December and silently produce a six-month "year" that still tied out.
        $end = (clone $start)->addYear()->subDay()->endOfDay();

        $fiscalYear = FiscalYear::updateOrCreate(
            ['year' => $year],
            ['starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString()],
        );

        // Twelve periods walked FORWARD from the start, so period 1 is the first month of the
        // fiscal year rather than January. An accountant reading "period 1" means the first month
        // they trade in, and every close report is ordered by period_no.
        for ($offset = 0; $offset < 12; $offset++) {
            $periodStart = (clone $start)->addMonths($offset)->startOfMonth()->startOfDay();
            $periodEnd = (clone $periodStart)->endOfMonth();

            AccountingPeriod::firstOrCreate(
                ['fiscal_year_id' => $fiscalYear->id, 'period_no' => $offset + 1],
                [
                    'starts_on' => $periodStart->toDateString(),
                    'ends_on' => $periodEnd->toDateString(),
                    'status' => 'open',
                ],
            );
        }

        return $fiscalYear;
    }
}
