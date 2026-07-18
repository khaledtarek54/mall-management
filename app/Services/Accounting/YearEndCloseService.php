<?php

namespace App\Services\Accounting;

use App\Models\FiscalYear;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    /**
     * The posted closing entries for a year — one per property (plus a consolidated
     * bucket for any P&L posted without an asset_id) since the F-80 fix. Empty if the
     * year isn't closed.
     *
     * @return Collection<int, JournalEntry>
     */
    public function closingEntriesFor(int $year): Collection
    {
        return JournalEntry::query()
            ->where('is_closing', true)
            ->where('status', 'posted')
            ->whereNull('reversal_of_id') // the closing entries themselves, not reversals
            ->whereYear('entry_date', $year)
            ->orderBy('id')
            ->get();
    }

    /** The latest posted closing entry for a year, if any (back-compat helper). */
    public function closingEntryFor(int $year): ?JournalEntry
    {
        return $this->closingEntriesFor($year)->last();
    }

    /**
     * Post the year-end closing entries (idempotent — returns the existing ones if the
     * year is already closed). One entry PER PROPERTY (plus a consolidated bucket for any
     * P&L posted without an asset_id), so each property's balance sheet rolls its own net
     * into its own retained earnings (F-80). Returns an empty collection if there's
     * nothing to close.
     *
     * @return Collection<int, JournalEntry>
     */
    public function close(int $year): Collection
    {
        // Guarantee the fiscal-year row exists so the lockForUpdate below actually binds
        // a row (otherwise a year never opened would lock nothing → the double-close guard
        // is a no-op).
        $this->calendar->ensureYear($year);

        // Lock-safe: serialize concurrent closes of the same year (double-click / two
        // accountants) on the fiscal-year row and re-check inside, so the closing
        // entries can never be posted twice (doubling the roll to retained earnings).
        return DB::transaction(function () use ($year) {
            FiscalYear::where('year', $year)->lockForUpdate()->first();

            $existing = $this->closingEntriesFor($year);
            if ($existing->isNotEmpty()) {
                return $existing;
            }

            return $this->postClosing($year);
        });
    }

    /** @return Collection<int, JournalEntry> */
    private function postClosing(int $year): Collection
    {
        $from = Carbon::create($year, 1, 1)->startOfDay();
        $to = Carbon::create($year, 12, 31)->endOfDay();

        $rows = $this->reports->profitLossBalancesByAsset($from, $to);
        if ($rows->isEmpty()) {
            return new Collection; // no P&L movement — nothing to close
        }

        $entries = new Collection;

        // Group the year's P&L by the ENTRY asset_id — the dimension the balance sheet
        // filters on — and post one balanced closing entry per bucket. A null asset_id
        // (P&L posted without a property) becomes its own consolidated bucket, so nothing
        // is stranded and the buckets sum to the consolidated net income.
        foreach ($rows->groupBy(fn ($r) => $r['asset_id'] ?? '__null__') as $key => $group) {
            $assetId = $key === '__null__' ? null : (int) $key;

            $lines = [];
            $netIncome = 0.0;
            foreach ($group as $row) {
                $netCredit = round($row['net_credit'], 2); // + = revenue-like, − = expense-like
                $netIncome += $netCredit;

                $lines[] = $netCredit > 0
                    ? ['ledger_account_id' => $row['account_id'], 'debit' => $netCredit, 'credit' => 0]
                    : ['ledger_account_id' => $row['account_id'], 'debit' => 0, 'credit' => -$netCredit];
            }

            $netIncome = round($netIncome, 2);
            // Resolve retained earnings for THIS property (per-asset mapping wins over the
            // global default) — the roll lands on the same account the balance sheet reads.
            $retained = $this->accounts->id('retained_earnings', $assetId);
            if ($netIncome > 0) {
                $lines[] = ['ledger_account_id' => $retained, 'debit' => 0, 'credit' => $netIncome];   // profit → equity up
            } elseif ($netIncome < 0) {
                $lines[] = ['ledger_account_id' => $retained, 'debit' => -$netIncome, 'credit' => 0];   // loss → equity down
            }

            $entries->push($this->posting->post([
                'entry_date' => $to->toDateString(),
                'description_en' => "Year-end closing FY{$year}",
                'description_ar' => "قيد إقفال السنة المالية {$year}",
                'asset_id' => $assetId,
                'is_manual' => true,
                'is_closing' => true,
                'lines' => $lines,
            ]));
        }

        return $entries;
    }

    /** Undo a year's close by voiding ALL its closing entries (posts a reversal each). */
    public function reopen(int $year): void
    {
        $entries = $this->closingEntriesFor($year);
        if ($entries->isEmpty()) {
            return;
        }

        // Reopen each distinct year-end period ONCE before voiding, so every reversal
        // posts back INSIDE the closed year (not deferred to today's period), keeping
        // per-year balance-sheet snapshots correct. All the per-property closing entries
        // share the same December period, hence the unique().
        $entries->load('period');
        $entries->pluck('period')->filter()->unique('id')
            ->each(function ($period): void {
                if (! $period->isOpen()) {
                    $this->periods->reopenPeriod($period);
                }
            });

        foreach ($entries as $entry) {
            $this->posting->void($entry, "Reopened FY{$year}");
        }
    }
}
