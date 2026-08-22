<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregation over posted journal lines — the source of every report.
 * Nothing is cached: balances are always re-derived from `journal_lines`, so the
 * books cannot drift.
 *
 * Reports include both `posted` entries AND `void` ones: a voided entry stays on
 * the books and is offset by its (posted) reversing entry, so counting both nets
 * to zero — dropping the original would leave the reversal as a phantom balance.
 *
 * Every report runs per-property or consolidated via $assetIds:
 *   - null            → no asset filter (true consolidated; callers must only pass
 *                       null for portfolio-wide users).
 *   - [id, id, ...]   → restrict to these property ids (a single asset, or a
 *                       restricted user's assigned set). An empty array matches
 *                       nothing — a safe default for a user with no properties.
 */
class LedgerReportService
{
    /** Entry statuses that represent real, reportable movements. */
    /** @see JournalEntry::REPORTABLE_STATUSES — the rule lives on the model, not here. */
    private const REPORTABLE = JournalEntry::REPORTABLE_STATUSES;

    /**
     * ميزان المراجعة — Trial Balance.
     *
     * One row per postable account that has movement, with total debit, total
     * credit, and the net balance shown on its normal side. The grand total of
     * the debit column must equal the grand total of the credit column.
     *
     * @return array{rows: Collection, total_debit: float, total_credit: float, balanced: bool}
     */
    /**
     * @param  bool  $includeZeroBalances  list postable accounts that had no movement at all (RP-02)
     */
    public function trialBalance(?array $assetIds = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null, bool $includeZeroBalances = false): array
    {
        $rows = $this->aggregate($assetIds, $from, $to)
            ->map(function ($row) {
                $debit = round((float) $row->debit_total, 2);
                $credit = round((float) $row->credit_total, 2);
                $net = round($debit - $credit, 2); // + = net debit, − = net credit

                return [
                    'account_id' => (int) $row->id,
                    'code' => $row->code,
                    'name_en' => $row->name_en,
                    'name_ar' => $row->name_ar,
                    'type' => $row->type,
                    'normal_balance' => $row->normal_balance,
                    'debit_total' => $debit,
                    'credit_total' => $credit,
                    // Trial-balance presentation: positive net sits in the debit
                    // column, negative net in the credit column.
                    'debit_balance' => $net > 0 ? $net : 0.0,
                    'credit_balance' => $net < 0 ? -$net : 0.0,
                ];
            })
            ->values();

        if ($includeZeroBalances) {
            $rows = $rows->concat($this->accountsWithNoMovement($rows->pluck('account_id')->all()))
                ->sortBy('code')
                ->values();
        }

        $totalDebit = round($rows->sum('debit_balance'), 2);
        $totalCredit = round($rows->sum('credit_balance'), 2);

        return [
            'rows' => $rows,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'balanced' => abs($totalDebit - $totalCredit) < 0.005,
        ];
    }

    /**
     * دفتر الأستاذ — General-ledger statement for one account: every posted line
     * in date order with a running balance (on the account's normal side).
     *
     * @return array{opening: float, lines: Collection, closing: float}
     */
    public function accountLedger(LedgerAccount $account, ?array $assetIds = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $sign = $account->normal_balance === 'debit' ? 1 : -1;

        $base = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->whereIn('je.status', self::REPORTABLE)
            ->whereNull('je.deleted_at')
            ->where('jl.ledger_account_id', $account->id)
            ->when($assetIds !== null, fn ($q) => $q->whereIn('je.asset_id', $assetIds));

        // Opening balance = movement strictly before `from`.
        $opening = 0.0;
        if ($from) {
            $before = (clone $base)->whereDate('je.entry_date', '<', $from->toDateString())
                ->selectRaw('COALESCE(SUM(jl.debit),0) as d, COALESCE(SUM(jl.credit),0) as c')
                ->first();
            $opening = round($sign * ((float) $before->d - (float) $before->c), 2);
        }

        $lines = (clone $base)
            ->when($from, fn ($q) => $q->whereDate('je.entry_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('je.entry_date', '<=', $to->toDateString()))
            ->orderBy('je.entry_date')
            ->orderBy('je.id')
            ->orderBy('jl.id')
            ->get([
                'je.number as entry_number',
                'je.entry_date',
                'je.description_en',
                'je.description_ar',
                // The narrative's key and data travel with the row, so the page can resolve it at
                // read time instead of showing the prose snapshot frozen at post time (EG-36).
                'je.description_key',
                'je.description_data',
                'jl.debit',
                'jl.credit',
                'jl.description as line_description',
                // The link back to the document. Selected as columns rather than hydrated, because
                // a statement of a thousand lines points at far fewer documents and
                // `App\Support\SourceDocumentUrl` resolves each one once.
                'je.source_type',
                'je.source_id',
            ]);

        $running = $opening;
        $lines = $lines->map(function ($row) use (&$running, $sign) {
            $running = round($running + $sign * ((float) $row->debit - (float) $row->credit), 2);
            $row->running_balance = $running;

            return $row;
        });

        return [
            'opening' => $opening,
            'lines' => $lines,
            'closing' => $running,
        ];
    }

    /**
     * قائمة الدخل — Income Statement (P&L) for a date range. Revenue accounts
     * (shown as net credit) minus expense accounts (net debit) = net profit.
     * Contra-revenue (e.g. sales returns) sits in revenue with a net debit, so it
     * correctly reduces total revenue.
     *
     * @return array{revenue: Collection, expense: Collection, total_revenue: float, total_expense: float, net_profit: float}
     */
    public function incomeStatement(?array $assetIds = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        // Exclude year-end closing entries — the P&L must show the period's ACTUAL
        // revenue/expense, not the zeroing entries that roll them to retained earnings.
        $rows = $this->aggregate($assetIds, $from, $to, excludeClosing: true);

        $revenue = collect();
        $expense = collect();
        foreach ($rows as $row) {
            $debit = (float) $row->debit_total;
            $credit = (float) $row->credit_total;
            if ($row->type === 'revenue') {
                $revenue->push($this->statementRow($row, round($credit - $debit, 2)));
            } elseif ($row->type === 'expense') {
                $expense->push($this->statementRow($row, round($debit - $credit, 2)));
            }
        }

        $totalRevenue = round($revenue->sum('amount'), 2);
        $totalExpense = round($expense->sum('amount'), 2);

        return [
            'revenue' => $revenue,
            'expense' => $expense,
            'total_revenue' => $totalRevenue,
            'total_expense' => $totalExpense,
            'net_profit' => round($totalRevenue - $totalExpense, 2),
        ];
    }

    /**
     * قائمة المركز المالي — Balance Sheet as of a date (cumulative from inception).
     * Assets (net debit) vs Liabilities + Equity (net credit) + net income for the
     * period not yet closed to retained earnings. Because the underlying trial
     * balance always balances, Assets ≡ Liabilities + Equity + NetIncome.
     *
     * @return array{assets: Collection, liabilities: Collection, equity: Collection,
     *     total_assets: float, total_liabilities: float, total_equity: float,
     *     net_income: float, total_equity_and_liabilities: float, balanced: bool}
     */
    public function balanceSheet(?array $assetIds = null, ?CarbonInterface $asOf = null): array
    {
        $rows = $this->aggregate($assetIds, null, $asOf);

        $assets = collect();
        $liabilities = collect();
        $equity = collect();
        $revenueTotal = 0.0;
        $expenseTotal = 0.0;

        foreach ($rows as $row) {
            $debit = (float) $row->debit_total;
            $credit = (float) $row->credit_total;
            match ($row->type) {
                'asset' => $assets->push($this->statementRow($row, round($debit - $credit, 2))),
                'liability' => $liabilities->push($this->statementRow($row, round($credit - $debit, 2))),
                'equity' => $equity->push($this->statementRow($row, round($credit - $debit, 2))),
                // Round per account THEN sum — matching incomeStatement() exactly, so
                // the balance sheet's net income can never penny-drift from the P&L.
                'revenue' => $revenueTotal += round($credit - $debit, 2),
                'expense' => $expenseTotal += round($debit - $credit, 2),
                default => null,
            };
        }

        $totalAssets = round($assets->sum('amount'), 2);
        $totalLiabilities = round($liabilities->sum('amount'), 2);
        $totalEquity = round($equity->sum('amount'), 2);
        $netIncome = round($revenueTotal - $expenseTotal, 2);
        $totalEquityAndLiabilities = round($totalLiabilities + $totalEquity + $netIncome, 2);

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity' => $totalEquity,
            'net_income' => $netIncome,
            'total_equity_and_liabilities' => $totalEquityAndLiabilities,
            'balanced' => abs($totalAssets - $totalEquityAndLiabilities) < 0.01,
        ];
    }

    /**
     * قائمة التدفقات النقدية — Cash-Flow Statement (indirect method) for a date range.
     *
     * Reconcile-by-construction: by double-entry, the change in cash over a period
     * equals the negated sum of every NON-cash account's movement. So we classify each
     * non-cash account into Operating / Investing / Financing (by the Egyptian code
     * ranges: 111 = cash & banks · 121 = gross non-current assets → investing · 122 =
     * accumulated depreciation → operating add-back · 222 = provisions → operating
     * add-back · 22 = non-current liabilities & equity → financing · everything else →
     * operating working capital), negate its
     * movement into that section, and the three sections sum to the actual cash movement.
     * `reconciled` asserts that double-entry identity — an integrity/regression guard on
     * this classification code; for balanced books it holds (it is not a data-error check).
     *
     * Year-end closing entries are excluded (they only move between non-cash P&L/equity
     * accounts — no cash effect — but would distort the net-income vs retained-earnings
     * split).
     *
     * @return array{net_income:float, adjustments:Collection, operating_total:float,
     *     investing:Collection, investing_total:float, financing:Collection,
     *     financing_total:float, net_change:float, cash_opening:float,
     *     cash_closing:float, cash_movement:float, reconciled:bool}
     */
    public function cashFlow(?array $assetIds = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $rows = $this->aggregate($assetIds, $from, $to, excludeClosing: true);

        $netIncome = 0.0;
        $adjustments = collect();
        $investing = collect();
        $financing = collect();
        $cashMovement = 0.0;

        foreach ($rows as $row) {
            $movement = round((float) $row->debit_total - (float) $row->credit_total, 2); // net debit
            $cashImpact = round(-$movement, 2); // this account's contribution to the change in cash

            // Cash & bank branch (111…) — the balance being explained, not a section.
            if (str_starts_with((string) $row->code, '111')) {
                $cashMovement = round($cashMovement + $movement, 2); // asset: net debit = cash in

                continue;
            }

            if ($cashImpact === 0.0) {
                continue;
            }

            if (in_array($row->type, ['revenue', 'expense'], true)) {
                $netIncome = round($netIncome + $cashImpact, 2); // Σ(credit−debit) = revenue − expense
            } elseif (str_starts_with((string) $row->code, '222')) {
                // Provisions (222…) are non-cash accruals (end-of-service, staff leave) —
                // an OPERATING add-back, not a financing flow. Financing under 22… is only
                // real funding movement (221 long-term loans). Must precede the 22 branch.
                $adjustments->push($this->statementRow($row, $cashImpact));
            } elseif ($row->type === 'equity' || str_starts_with((string) $row->code, '22')) {
                $financing->push($this->statementRow($row, $cashImpact));
            } elseif (str_starts_with((string) $row->code, '122')) {
                // Accumulated depreciation (122…) is the non-cash counterpart of depreciation
                // expense — an OPERATING add-back, not an investing flow. Investing is only the
                // gross non-current assets (121…) that move on actual cash purchases/disposals.
                $adjustments->push($this->statementRow($row, $cashImpact));
            } elseif (str_starts_with((string) $row->code, '12')) {
                $investing->push($this->statementRow($row, $cashImpact));
            } else {
                // current assets (non-cash) + current liabilities + any custom account.
                $adjustments->push($this->statementRow($row, $cashImpact));
            }
        }

        $operatingTotal = round($netIncome + $adjustments->sum('amount'), 2);
        $investingTotal = round($investing->sum('amount'), 2);
        $financingTotal = round($financing->sum('amount'), 2);
        $netChange = round($operatingTotal + $investingTotal + $financingTotal, 2);

        // Opening cash = cumulative cash-branch balance strictly before `from`.
        $cashOpening = 0.0;
        if ($from) {
            $cashOpening = round(
                $this->aggregate($assetIds, null, $from->copy()->subDay())
                    ->filter(fn ($r) => str_starts_with((string) $r->code, '111'))
                    ->sum(fn ($r) => (float) $r->debit_total - (float) $r->credit_total),
                2
            );
        }

        return [
            'net_income' => $netIncome,
            'adjustments' => $adjustments->values(),
            'operating_total' => $operatingTotal,
            'investing' => $investing->values(),
            'investing_total' => $investingTotal,
            'financing' => $financing->values(),
            'financing_total' => $financingTotal,
            'net_change' => $netChange,
            'cash_opening' => $cashOpening,
            'cash_closing' => round($cashOpening + $cashMovement, 2),
            'cash_movement' => round($cashMovement, 2),
            // The three sections must explain the actual cash movement (double-entry).
            'reconciled' => abs($netChange - $cashMovement) < 0.01,
        ];
    }

    /**
     * Per-account net for revenue + expense accounts (net_credit = credit − debit),
     * excluding prior closing entries. Used by the year-end close to zero each P&L
     * account. Only accounts with movement are returned.
     *
     * @return Collection<int, array{account_id:int, code:string, type:string, net_credit:float}>
     */
    public function profitLossBalances(?array $assetIds, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->aggregate($assetIds, $from, $to, excludeClosing: true)
            ->filter(fn ($r) => in_array($r->type, ['revenue', 'expense'], true))
            ->map(fn ($r) => [
                'account_id' => (int) $r->id,
                'code' => $r->code,
                'type' => $r->type,
                'net_credit' => round((float) $r->credit_total - (float) $r->debit_total, 2),
            ])
            ->filter(fn ($r) => abs($r['net_credit']) >= 0.005)
            ->values();
    }

    /**
     * P&L per (asset_id, account) for the range, excluding prior closing entries — the
     * per-property split the year-end close needs so each property's revenue/expense is
     * zeroed into ITS OWN retained earnings. Fixes F-80: a single consolidated close
     * posted with a null asset_id, and `aggregate()` filters on `je.asset_id`, so every
     * per-property balance sheet read retained earnings 0 forever. Rows whose entry has a
     * null asset_id are returned under `asset_id => null` and closed as a consolidated
     * bucket, so no P&L is ever stranded and the buckets sum to the consolidated net.
     * Only (asset, account) pairs with movement are returned.
     *
     * @return Collection<int, array{asset_id:int|null, account_id:int, code:string, type:string, net_credit:float}>
     */
    public function profitLossBalancesByAsset(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('ledger_accounts as la', 'la.id', '=', 'jl.ledger_account_id')
            ->whereIn('je.status', self::REPORTABLE)
            ->whereNull('je.deleted_at')
            ->where('je.is_closing', false)
            ->whereIn('la.type', ['revenue', 'expense'])
            ->whereDate('je.entry_date', '>=', $from->toDateString())
            ->whereDate('je.entry_date', '<=', $to->toDateString())
            ->groupBy('je.asset_id', 'la.id', 'la.code', 'la.type')
            ->orderBy('je.asset_id')
            ->orderBy('la.code')
            ->get([
                'je.asset_id',
                'la.id',
                'la.code',
                'la.type',
                DB::raw('COALESCE(SUM(jl.debit),0) as debit_total'),
                DB::raw('COALESCE(SUM(jl.credit),0) as credit_total'),
            ])
            ->map(fn ($r) => [
                'asset_id' => $r->asset_id === null ? null : (int) $r->asset_id,
                'account_id' => (int) $r->id,
                'code' => $r->code,
                'type' => $r->type,
                'net_credit' => round((float) $r->credit_total - (float) $r->debit_total, 2),
            ])
            ->filter(fn ($r) => abs($r['net_credit']) >= 0.005)
            ->values();
    }

    /** @return array<string, mixed> */
    private function statementRow(object $row, float $amount): array
    {
        return [
            'account_id' => (int) $row->id,
            'code' => $row->code,
            'name_en' => $row->name_en,
            'name_ar' => $row->name_ar,
            'amount' => $amount,
        ];
    }

    /**
     * Aggregate posted debit/credit per postable account with movement.
     */
    /**
     * Postable accounts that produced no line at all in the range.
     *
     * `aggregate()` starts from `journal_lines`, so an account nobody has posted to is absent from
     * every ledger report rather than present at zero. That is the right default — a trial balance
     * of 400 rows, 300 of them zero, is harder to read, not more complete.
     *
     * But it is the wrong answer for the one thing a trial balance is FOR: proving completeness.
     * An accountant reconciling asks "is the deposits-held account really nil, or did I forget to
     * map it?", and absence answers neither. Yardi offers the same switch, and offers it here
     * rather than on the income statement, where a hundred zero revenue accounts would be noise.
     *
     * Zero rows are structural, so they carry zero in BOTH columns and cannot move the totals —
     * `balanced` means exactly what it did before the switch was flipped.
     *
     * @param  array<int, int>  $alreadyListed
     * @return Collection<int, array<string, mixed>>
     */
    protected function accountsWithNoMovement(array $alreadyListed): Collection
    {
        return LedgerAccount::query()
            ->where('is_postable', true)
            ->whereNotIn('id', $alreadyListed ?: [0])
            ->orderBy('code')
            ->get()
            ->map(fn (LedgerAccount $account) => [
                'account_id' => (int) $account->id,
                'code' => $account->code,
                'name_en' => $account->name_en,
                'name_ar' => $account->name_ar,
                'type' => $account->type,
                'normal_balance' => $account->normal_balance,
                'debit_total' => 0.0,
                'credit_total' => 0.0,
                'debit_balance' => 0.0,
                'credit_balance' => 0.0,
            ]);
    }

    /**
     * The money this statement is NOT showing because it is filed against no property (EG-27).
     *
     * `aggregate()` and {@see accountLedger()} both scope with `whereIn('je.asset_id', $ids)`, and
     * `whereIn` never matches NULL — so a journal entry with no property is invisible in every
     * statement an operator can open. The year-end close already knew better:
     * {@see plByAssetAndAccount()} buckets those rows under `asset_id => null` precisely "so no P&L
     * is ever stranded". The close and the reports disagreed, and the reports said nothing.
     *
     * **This does not fold them in, deliberately.** A null `asset_id` on a money document is
     * portfolio-level overhead visible from every mall
     * (`#[PropertyOwned(portfolioRowsWhenNull: true)]`), so absorbing it into each property's
     * statement would show one operator-wide insurance bill in full on all three malls and none of
     * their figures would be right. It is reported BESIDE the statement instead: the operator can
     * see that unallocated money exists, and `atriom:audit-property-dimension` is what finds and
     * fixes it. Since `PropertyField` pinned the pickers no screen can create one any more, so the
     * remaining sources are a CSV import and a migration — the moments nobody is watching a report.
     *
     * Null when the read is unscoped (nothing is being excluded, so there is nothing to warn about)
     * or when there are no such entries.
     *
     * @param  array<int, int>|null  $assetIds
     * @return array{count: int, total: float}|null
     */
    public function unallocated(?array $assetIds, ?CarbonInterface $from = null, ?CarbonInterface $to = null): ?array
    {
        if ($assetIds === null) {
            return null;
        }

        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->whereIn('je.status', self::REPORTABLE)
            ->whereNull('je.deleted_at')
            ->whereNull('je.asset_id')
            ->when($from, fn ($q) => $q->whereDate('je.entry_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('je.entry_date', '<=', $to->toDateString()))
            // Sized by DEBITS. An entry balances, so its debit total is what the entry is "worth" —
            // summing both sides would double every figure and read as twice the exposure.
            ->selectRaw('COUNT(DISTINCT je.id) as n, COALESCE(SUM(jl.debit),0) as t')
            ->first();

        $count = (int) ($row->n ?? 0);

        return $count === 0 ? null : ['count' => $count, 'total' => round((float) $row->t, 2)];
    }

    protected function aggregate(?array $assetIds, ?CarbonInterface $from, ?CarbonInterface $to, bool $excludeClosing = false): Collection
    {
        return DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('ledger_accounts as la', 'la.id', '=', 'jl.ledger_account_id')
            ->whereIn('je.status', self::REPORTABLE)
            ->whereNull('je.deleted_at')
            ->when($excludeClosing, fn ($q) => $q->where('je.is_closing', false))
            ->when($assetIds !== null, fn ($q) => $q->whereIn('je.asset_id', $assetIds))
            ->when($from, fn ($q) => $q->whereDate('je.entry_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('je.entry_date', '<=', $to->toDateString()))
            ->groupBy('la.id', 'la.code', 'la.name_en', 'la.name_ar', 'la.type', 'la.normal_balance')
            ->orderBy('la.code')
            ->get([
                'la.id',
                'la.code',
                'la.name_en',
                'la.name_ar',
                'la.type',
                'la.normal_balance',
                DB::raw('COALESCE(SUM(jl.debit),0) as debit_total'),
                DB::raw('COALESCE(SUM(jl.credit),0) as credit_total'),
            ]);
    }
}
