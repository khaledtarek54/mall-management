<?php

namespace App\Services\Accounting;

use App\Models\BudgetLine;
use App\Models\LedgerAccount;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The operating budget, read in the shape the income statement already speaks.
 *
 * `ComparativeStatementService` could compare a period against the one before it or the same one a
 * year earlier. Both answer "is this normal?"; neither answers **"is this what we planned?"** —
 * which is the question a monthly review is built around and the one an owner asks first.
 *
 * The trick that keeps this small: rather than teaching the comparison a third kind of thing,
 * {@see self::asIncomeStatement()} returns the SAME array shape `LedgerReportService::incomeStatement()`
 * returns. The budget then slots in wherever a prior period would have, and every downstream column
 * — change, change %, the totals, the CSV — works unmodified.
 */
class BudgetService
{
    /**
     * Budgeted revenue and expense over a date range, shaped exactly like an income statement.
     *
     * A month counts when its FIRST day falls inside the range. Partial months are not apportioned:
     * a budget is a monthly plan, and pro-rating it to a fortnight would invent a precision the
     * number never had.
     *
     * @return array{revenue: Collection, expense: Collection, total_revenue: float, total_expense: float, net_profit: float}
     */
    public function asIncomeStatement(CarbonImmutable $from, CarbonImmutable $to, ?array $assetIds = null): array
    {
        $rows = BudgetLine::query()
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'budget_lines.ledger_account_id')
            ->when($assetIds !== null, fn ($q) => $q->whereIn('budget_lines.asset_id', $assetIds))
            ->whereIn('ledger_accounts.type', ['revenue', 'expense'])
            ->get([
                'ledger_accounts.id', 'ledger_accounts.code', 'ledger_accounts.name_en',
                'ledger_accounts.name_ar', 'ledger_accounts.type',
                'budget_lines.fiscal_year', 'budget_lines.month', 'budget_lines.amount',
            ])
            ->filter(function ($row) use ($from, $to) {
                $monthStart = CarbonImmutable::create((int) $row->fiscal_year, (int) $row->month, 1);

                return $monthStart->betweenIncluded($from->startOfDay(), $to->startOfDay());
            });

        $revenue = collect();
        $expense = collect();

        foreach ($rows->groupBy('id') as $accountRows) {
            $first = $accountRows->first();

            $line = [
                'account_id' => (int) $first->id,
                'code' => $first->code,
                'name_en' => $first->name_en,
                'name_ar' => $first->name_ar,
                'amount' => round((float) $accountRows->sum('amount'), 2),
            ];

            $first->type === 'revenue' ? $revenue->push($line) : $expense->push($line);
        }

        $totalRevenue = round($revenue->sum('amount'), 2);
        $totalExpense = round($expense->sum('amount'), 2);

        return [
            'revenue' => $revenue->sortBy('code')->values(),
            'expense' => $expense->sortBy('code')->values(),
            'total_revenue' => $totalRevenue,
            'total_expense' => $totalExpense,
            'net_profit' => round($totalRevenue - $totalExpense, 2),
        ];
    }

    /** Has anything been budgeted at all for this year? Used to say "no budget" rather than "zero". */
    public function existsFor(int $year, ?array $assetIds = null): bool
    {
        return BudgetLine::query()
            ->where('fiscal_year', $year)
            ->when($assetIds !== null, fn ($q) => $q->whereIn('asset_id', $assetIds))
            ->exists();
    }

    /**
     * Replace a property's budget for a year from pasted text.
     *
     * `code, amount` spreads an annual figure evenly across twelve months; `code, month, amount`
     * sets one month exactly. Both forms may be mixed in one paste, because a first budget is
     * usually annual with two or three seasonal lines called out.
     *
     * **It REPLACES the year rather than adding to it.** Importing twice would otherwise double the
     * plan silently, and unlike an opening balance there is no review step to catch it — so the
     * write is a delete-then-insert inside one transaction, which is also what "revising the
     * budget" means to the person doing it.
     *
     * @return array{lines: int, accounts: int}
     *
     * @throws DomainException on an unknown account, a non-P&L account, or a bad month
     */
    public function import(string $raw, int $year, int $assetId): array
    {
        $parsed = $this->parse($raw);

        if ($parsed === []) {
            throw new DomainException(__('admin.budget.errors.empty'));
        }

        $codes = array_unique(array_column($parsed, 0));
        $accounts = LedgerAccount::query()->whereIn('code', $codes)->get()->keyBy('code');

        $errors = [];

        foreach ($parsed as [$code, $month, $amount]) {
            $account = $accounts[$code] ?? null;

            if ($account === null) {
                $errors[] = __('admin.budget.errors.unknown_account', ['code' => $code]);
            } elseif (! in_array($account->type, ['revenue', 'expense'], true)) {
                // A budget is a P&L plan. Budgeting a balance-sheet account would produce a line
                // the income statement can never show, which reads as the import having failed.
                $errors[] = __('admin.budget.errors.not_pl', ['code' => $code]);
            } elseif (! $account->is_postable) {
                $errors[] = __('admin.budget.errors.summary_account', ['code' => $code]);
            } elseif ($month !== null && ($month < 1 || $month > 12)) {
                $errors[] = __('admin.budget.errors.bad_month', ['code' => $code, 'month' => $month]);
            }
        }

        if ($errors !== []) {
            throw new DomainException(implode(' · ', array_slice(array_unique($errors), 0, 5)));
        }

        return DB::transaction(function () use ($parsed, $accounts, $year, $assetId) {
            BudgetLine::query()
                ->where('asset_id', $assetId)
                ->where('fiscal_year', $year)
                ->forceDelete();

            $written = 0;
            $touched = [];

            foreach ($parsed as [$code, $month, $amount]) {
                $accountId = $accounts[$code]->id;
                $touched[$accountId] = true;

                // An annual figure spreads evenly, with the rounding remainder landing on December
                // so twelve months sum back to exactly what was budgeted.
                $months = $month !== null ? [$month => $amount] : $this->spread($amount);

                foreach ($months as $m => $value) {
                    BudgetLine::updateOrCreate(
                        ['asset_id' => $assetId, 'ledger_account_id' => $accountId, 'fiscal_year' => $year, 'month' => $m],
                        ['amount' => $value],
                    );
                    $written++;
                }
            }

            return ['lines' => $written, 'accounts' => count($touched)];
        });
    }

    /**
     * Twelve equal months, remainder on December.
     *
     * Without the remainder the twelve months of a 100,000 budget sum to 99,999.96, and an operator
     * comparing the annual total against what they typed finds four piastres missing and no
     * explanation.
     *
     * @return array<int, float>
     */
    private function spread(float $annual): array
    {
        $monthly = round($annual / 12, 2);
        $months = array_fill(1, 11, $monthly);
        $months[12] = round($annual - ($monthly * 11), 2);

        return $months;
    }

    /**
     * `code, amount` or `code, month, amount`, one per line.
     *
     * Same tolerance as the opening-balance paste, and the same separator rule: chosen once per
     * line, most-specific first, so a tab-separated `1,250,000.50` is not shredded by the comma.
     *
     * @return array<int, array{0:string, 1:?int, 2:float}>
     */
    private function parse(string $raw): array
    {
        $out = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($raw)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $cells = str_contains($line, "\t")
                ? array_map('trim', explode("\t", $line))
                : (str_contains($line, ';')
                    ? array_map('trim', explode(';', $line))
                    : array_map(fn ($c) => trim((string) $c), str_getcsv($line, ',', '"', '\\')));

            if (count($cells) < 2 || ! preg_match('/^[0-9][0-9.\-]*$/', $cells[0])) {
                continue;   // blank, or a header row — detected by shape, since the sheet may be Arabic
            }

            if (count($cells) >= 3 && $cells[2] !== '') {
                $out[] = [$cells[0], (int) $this->amount($cells[1]), $this->amount($cells[2])];
            } else {
                $out[] = [$cells[0], null, $this->amount($cells[1])];
            }
        }

        return $out;
    }

    private function amount(string $cell): float
    {
        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $cell));

        return $clean === '' || $clean === '-' ? 0.0 : round((float) $clean, 2);
    }
}
