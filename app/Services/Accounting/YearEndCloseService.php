<?php

namespace App\Services\Accounting;

use App\Models\FiscalYear;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * قيود الإقفال — year-end closing entry. Zeros every revenue and expense account
 * into retained earnings, so the new year starts fresh and accumulated profit
 * lands in equity. Flagged `is_closing` so it's excluded from the income statement
 * (which must show the year's actual P&L) but reflected in the trial balance and
 * balance sheet (where the P&L accounts read zero after the close).
 *
 * To zero an account you post the opposite of its balance:
 *   revenue (net credit) → Debit it;  expense (net debit) → Credit it.
 *   the net (profit/loss) → Credit/Debit retained earnings so the entry balances.
 */
class YearEndCloseService
{
    public function __construct(
        private LedgerReportService $reports,
        private AccountResolver $accounts,
        private JournalPostingService $posting,
        private PeriodService $periods,
        private FiscalCalendar $calendar,
    ) {}

    /** The posted closing entry for a year, if one exists. */
    public function closingEntryFor(int $year): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('is_closing', true)
            ->where('status', 'posted')
            ->whereNull('reversal_of_id') // the closing entry itself, not a reversal of one
            ->whereYear('entry_date', $year)
            ->latest('id')
            ->first();
    }

    /**
     * Post the year-end closing entry (idempotent — returns the existing one if the
     * year is already closed). Returns null if there's nothing to close.
     */
    public function close(int $year): ?JournalEntry
    {
        // Guarantee the fiscal-year row exists so the lockForUpdate below actually binds
        // a row (otherwise a year never opened would lock nothing → the double-close guard
        // is a no-op).
        $this->calendar->ensureYear($year);

        // Lock-safe: serialize concurrent closes of the same year (double-click / two
        // accountants) on the fiscal-year row and re-check inside, so the closing
        // entry can never be posted twice (doubling the roll to retained earnings).
        return DB::transaction(function () use ($year) {
            FiscalYear::where('year', $year)->lockForUpdate()->first();

            if ($existing = $this->closingEntryFor($year)) {
                return $existing;
            }

            return $this->postClosing($year);
        });
    }

    private function postClosing(int $year): ?JournalEntry
    {
        $from = Carbon::create($year, 1, 1)->startOfDay();
        $to = Carbon::create($year, 12, 31)->endOfDay();

        $balances = $this->reports->profitLossBalances(null, $from, $to);
        if ($balances->isEmpty()) {
            return null; // no P&L movement — nothing to close
        }

        $lines = [];
        $netIncome = 0.0;
        foreach ($balances as $row) {
            $netCredit = round($row['net_credit'], 2); // + = revenue-like, − = expense-like
            $netIncome += $netCredit;

            $lines[] = $netCredit > 0
                ? ['ledger_account_id' => $row['account_id'], 'debit' => $netCredit, 'credit' => 0]
                : ['ledger_account_id' => $row['account_id'], 'debit' => 0, 'credit' => -$netCredit];
        }

        $netIncome = round($netIncome, 2);
        $retained = $this->accounts->id('retained_earnings');
        if ($netIncome > 0) {
            $lines[] = ['ledger_account_id' => $retained, 'debit' => 0, 'credit' => $netIncome];   // profit → equity up
        } elseif ($netIncome < 0) {
            $lines[] = ['ledger_account_id' => $retained, 'debit' => -$netIncome, 'credit' => 0];   // loss → equity down
        }

        return $this->posting->post([
            'entry_date' => $to->toDateString(),
            'description_en' => "Year-end closing FY{$year}",
            'description_ar' => "قيد إقفال السنة المالية {$year}",
            'is_manual' => true,
            'is_closing' => true,
            'lines' => $lines,
        ]);
    }

    /** Undo a year's close by voiding its closing entry (posts a reversal). */
    public function reopen(int $year): void
    {
        $entry = $this->closingEntryFor($year);
        if (! $entry) {
            return;
        }

        // Reopen the closing entry's own period first so the reversal posts back
        // INSIDE the closed year (not deferred to today's period), keeping per-year
        // balance-sheet snapshots correct.
        $entry->loadMissing('period');
        if ($entry->period && ! $entry->period->isOpen()) {
            $this->periods->reopenPeriod($entry->period);
        }

        $this->posting->void($entry, "Reopened FY{$year}");
    }
}
