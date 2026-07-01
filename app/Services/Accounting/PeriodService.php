<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use Illuminate\Support\Facades\DB;

/**
 * إقفال الفترات — open/close accounting periods and fiscal years. Closing a period
 * makes it final: JournalPostingService refuses to post (or reverse) into a closed
 * period, so reported figures for it can't change. Reopening allows corrections.
 */
class PeriodService
{
    public function closePeriod(AccountingPeriod $period): AccountingPeriod
    {
        $period->update(['status' => 'closed']);

        return $period;
    }

    public function reopenPeriod(AccountingPeriod $period): AccountingPeriod
    {
        $period->update(['status' => 'open']);

        return $period;
    }

    /** Close every period in the year, then mark the year closed (one transaction). */
    public function closeFiscalYear(FiscalYear $year): FiscalYear
    {
        return DB::transaction(function () use ($year) {
            $year->periods()->update(['status' => 'closed']);
            $year->update(['status' => 'closed']);

            return $year->refresh();
        });
    }

    public function reopenFiscalYear(FiscalYear $year): FiscalYear
    {
        return DB::transaction(function () use ($year) {
            $year->periods()->update(['status' => 'open']);
            $year->update(['status' => 'open']);

            return $year->refresh();
        });
    }
}
