<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Support\LedgerRealtimeSync;
use App\Support\MorphMap;
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

    /**
     * Reopen ONE month so corrections can be posted into it again.
     *
     * **Refused while the year's closing entry still stands (SW-136).** `YearEndCloseService::close()`
     * posts, per property, the entry that zeroes that year's P&L into retained earnings, reading
     * `profitLossBalancesByAsset()` ONCE at close time — and it is IDEMPOTENT, returning the existing
     * entries rather than rolling anything new. So an entry posted into a month reopened afterwards
     * is permanently outside retained earnings: re-closing cannot pick it up, and next year's close
     * cannot either, because its fiscal span does not cover the entry's date.
     *
     * Nothing downstream notices. `JournalPostingService::assertOpenPeriodFor()` gates on the
     * PERIOD's status and has never read the fiscal year's, so the posting succeeds; and the balance
     * sheet still balances, because `balanceSheet()` derives `net_income` from whatever P&L is left
     * un-rolled as at the date and simply carries the orphaned figure for ever — under a year whose
     * income statement no longer agrees with its equity.
     *
     * **The escape is the YEAR, and it already exists**: "Reopen year" voids the closing entries and
     * unlocks every month, the accountant posts, and closes the year again — the exact sequence
     * `ListAccountingPeriods::year_end_reopen` performs. The refusal names it.
     *
     * @throws \DomainException when a posted, un-reversed closing entry stands for this period's year
     */
    public function reopenPeriod(AccountingPeriod $period): AccountingPeriod
    {
        $this->assertNoStandingClosingEntry($period);

        return $this->forceReopenPeriod($period);
    }

    /**
     * Reopen a period so a year-end REVERSAL can be posted back inside it.
     *
     * The one way past the guard above, and it is not an exception to the rule but the rule's own
     * mechanism: {@see YearEndCloseService::reopen()} relaxes the year-end period precisely so that
     * the VOID of the closing entry lands in the year it closed rather than being deferred to
     * today's period, and the entry is gone by the end of that call. Named rather than passed as a
     * boolean, so `grep` finds every caller that takes the escape.
     */
    public function forceReopenPeriod(AccountingPeriod $period): AccountingPeriod
    {
        $period->update(['status' => 'open']);

        return $period;
    }

    /**
     * Refuse a lone reopen while the year's closing entry stands.
     *
     * `YearEndCloseService::closingEntriesFor()` is the ONE definition of "a standing closing entry"
     * — posted, not a reversal, dated inside the FISCAL year's own span — and it is the same query
     * `close()` re-checks under its lock as the double-close guard, so this can never disagree with
     * what a re-close would find. Deliberately keyed on the ENTRY rather than on `fiscal_years.status`:
     * a year marked closed with nothing rolled (no P&L movement) does no harm, and a year-end entry
     * posted without `closeFiscalYear()` does.
     *
     * Resolved through the container rather than injected: `YearEndCloseService` names `PeriodService`
     * in its own constructor, so declaring it in this one would be a container cycle.
     */
    private function assertNoStandingClosingEntry(AccountingPeriod $period): void
    {
        $year = $period->fiscalYear?->year;

        if ($year === null) {
            return;
        }

        if (app(YearEndCloseService::class)->closingEntriesFor($year)->isEmpty()) {
            return;
        }

        throw new \DomainException(__('admin.refusals.period_reopen_year_is_closed', [
            'month' => $period->starts_on?->format('Y-m') ?? 'P'.$period->period_no,
            'year' => $year,
        ]));
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
                // **DAY bounds, not midnight bounds.** `starts_on`/`ends_on` are `date` casts, so
                // binding the Carbon instances compiles to `between '2026-08-01 00:00:00' and
                // '2026-08-31 00:00:00'` — measured on this exact query at HEAD (2026-09-04).
                // Every column in SOURCE_DATE_COLUMNS is a `date` except ONE:
                // `sla_penalties.applied_at` is a `dateTime`. So a penalty applied at 09:15 on the
                // LAST day of the period fell outside the upper bound and this gate never saw it —
                // and part (b) exists precisely for the never-posted document (real-time off, queue
                // backlogged, a best-effort sync that failed once). Close the period and its post is
                // stranded for good: posting into a closed period throws, so the vendor keeps the
                // deduction on their bill while the ledger never records it.
                //
                // Half-open on DATE STRINGS rather than `endOfDay()` datetimes, because that is the
                // one form correct for a `date` column AND a `dateTime` column on BOTH drivers:
                // sqlite compares these as strings, and a bare '2026-08-01' sorts BEFORE
                // '2026-08-01 00:00:00', so a datetime lower bound silently drops the period's
                // FIRST day for any row written as a plain date.
                //
                // `copy()` is load-bearing: a date cast is memoised, so `$period` hands back the
                // SAME Carbon instance on every iteration of the enclosing per-source loop and
                // `addDay()` on it would walk the window forward one class at a time.
                $query->where($dateColumn, '>=', $period->starts_on->toDateString())
                    ->where($dateColumn, '<', $period->ends_on->copy()->addDay()->toDateString())
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
        // `journal_entries.source_type` stores a morph ALIAS, so `class_exists('invoice')` is false.
        // Resolving it matters more here than almost anywhere: this gate refuses to close a period
        // holding a document that has drifted from its posted entry, and an unresolvable source
        // means it finds no drift and closes anyway — stranding the correcting post in a closed
        // period, silently, because posting into a closed period throws.
        $type = MorphMap::resolve($type);

        if ($type === null) {
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
