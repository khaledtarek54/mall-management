<?php

namespace App\Services\Accounting;

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
    private const REPORTABLE = ['posted', 'void'];

    /**
     * ميزان المراجعة — Trial Balance.
     *
     * One row per postable account that has movement, with total debit, total
     * credit, and the net balance shown on its normal side. The grand total of
     * the debit column must equal the grand total of the credit column.
     *
     * @return array{rows: Collection, total_debit: float, total_credit: float, balanced: bool}
     */
    public function trialBalance(?array $assetIds = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
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
                'jl.debit',
                'jl.credit',
                'jl.description as line_description',
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
        $rows = $this->aggregate($assetIds, $from, $to);

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
                'revenue' => $revenueTotal += ($credit - $debit),
                'expense' => $expenseTotal += ($debit - $credit),
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
    protected function aggregate(?array $assetIds, ?CarbonInterface $from, ?CarbonInterface $to): Collection
    {
        return DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('ledger_accounts as la', 'la.id', '=', 'jl.ledger_account_id')
            ->whereIn('je.status', self::REPORTABLE)
            ->whereNull('je.deleted_at')
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
