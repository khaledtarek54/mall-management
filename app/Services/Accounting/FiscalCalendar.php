<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use Illuminate\Support\Carbon;

/**
 * Opens fiscal years and their 12 monthly periods (idempotent). Reused by the
 * seeder, tests, and (later) the period-management UI. A calendar year is
 * assumed (Jan–Dec); a fiscal year starting in another month is a future option.
 */
class FiscalCalendar
{
    public function ensureYear(int $year): FiscalYear
    {
        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = Carbon::create($year, 12, 31)->endOfDay();

        $fiscalYear = FiscalYear::updateOrCreate(
            ['year' => $year],
            ['starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString()],
        );

        for ($month = 1; $month <= 12; $month++) {
            $periodStart = Carbon::create($year, $month, 1)->startOfDay();
            $periodEnd = (clone $periodStart)->endOfMonth();

            AccountingPeriod::firstOrCreate(
                ['fiscal_year_id' => $fiscalYear->id, 'period_no' => $month],
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
