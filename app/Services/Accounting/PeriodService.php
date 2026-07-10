<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Support\LedgerRealtimeSync;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * إقفال الفترات — open/close accounting periods and fiscal years. Closing a period
 * makes it final: JournalPostingService refuses to post (or reverse) into a closed
 * period, so reported figures for it can't change. Reopening allows corrections.
 *
 * Close gate (Phase 4): a period can't be closed while any document whose entry lives in
 * it still has a pending ledger change (an edit/delete not yet re-synced) — closing then
 * would strand that re-post/void in the now-closed period (the closed-period reversal trap).
 * The accountant syncs first ("Post to GL now" / accounting:sync-ledger --all), then closes.
 */
class PeriodService
{
    public function closePeriod(AccountingPeriod $period): AccountingPeriod
    {
        $this->assertPeriodsReconciled([$period->id]);

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
        $this->assertPeriodsReconciled($year->periods()->pluck('id')->all());

        return DB::transaction(function () use ($year) {
            $year->periods()->update(['status' => 'closed']);
            $year->update(['status' => 'closed']);

            return $year->refresh();
        });
    }

    /**
     * The close gate: refuse if any posting source whose posted entry lives in one of these
     * periods is out of sync with its current state (an edit/delete not yet re-synced). Closing
     * would strand that pending re-post/void in the closed period. Read-only (dry-run via
     * LedgerPoster::wouldChange). Manual/closing entries (no source) are ignored. Public so the
     * year-end flow can gate BEFORE it posts the closing entry (avoiding an orphaned entry).
     */
    public function assertPeriodsReconciled(array $periodIds): void
    {
        if (empty($periodIds)) {
            return;
        }

        $poster = app(LedgerPoster::class);
        $pending = 0;
        $postedInPeriod = []; // source keys that already have a posted entry in these periods

        // (a) Documents whose POSTED entry lives in these periods — an edit/delete not yet
        //     re-synced would re-post/void into the period.
        $entries = JournalEntry::query()
            ->whereIn('accounting_period_id', $periodIds)
            ->where('status', 'posted')
            ->whereNotNull('source_type')
            ->get(['source_type', 'source_id']);
        foreach ($entries as $ref) {
            $key = $ref->source_type.':'.$ref->source_id;
            if (isset($postedInPeriod[$key])) {
                continue;
            }
            $postedInPeriod[$key] = true;
            $model = $this->resolveSource($ref->source_type, $ref->source_id);
            if ($model && $poster->wouldChange($model)) {
                $pending++;
            }
        }

        // (b) Documents DATED in these periods that are NOT yet posted here — a fresh post would
        //     land IN the period, so closing it would strand that post forever (posting into a
        //     closed period throws). The gate is otherwise blind to a never-posted document
        //     (real-time off / queue backlogged / a best-effort sync job that failed once). Only
        //     the genuinely-unposted remainder reaches wouldChange, so this stays cheap.
        $periods = AccountingPeriod::whereIn('id', $periodIds)->get(['starts_on', 'ends_on']);
        foreach (LedgerRealtimeSync::SOURCE_DATE_COLUMNS as $class => $dateColumn) {
            foreach ($periods as $period) {
                $query = $class::query();
                if (method_exists(new $class, 'trashed')) {
                    $query->withTrashed();
                }
                $query->whereBetween($dateColumn, [$period->starts_on, $period->ends_on])
                    ->chunkById(500, function ($models) use ($poster, &$pending, $postedInPeriod) {
                        foreach ($models as $model) {
                            if (isset($postedInPeriod[$model->getMorphClass().':'.$model->getKey()])) {
                                continue; // already checked in (a)
                            }
                            if ($poster->wouldChange($model)) {
                                $pending++;
                            }
                        }
                    });
            }
        }

        if ($pending > 0) {
            throw new \DomainException(__('admin.notifications.close_blocked_unsynced', ['count' => $pending]));
        }
    }

    /** Re-fetch a journal entry's source model (withTrashed, since a deleted source still needs voiding). */
    private function resolveSource(string $type, int|string $id): ?Model
    {
        if (! class_exists($type)) {
            return null;
        }
        $query = $type::query();
        if (method_exists(new $type, 'trashed')) {
            $query->withTrashed();
        }

        return $query->find($id);
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
