<?php

namespace App\Services\Reports;

use App\Models\Invoice;
use App\Support\IncomeStatementLayout;
use App\Support\JournalNarrative;
use App\Support\StatementGroups;
use Illuminate\Support\Collection;

/**
 * Turns a computed report into `[headers, rows]` for CSV export — the accountant's working format.
 * Kept out of the Filament pages so the row shape is unit-testable directly (a streamed response is
 * not), and so every report flattens the same way. Account names follow the current locale.
 *
 * Amounts are emitted as plain numbers (no thousands separators / currency symbol) so a spreadsheet
 * reads them as numbers, not text — the whole point of CSV over PDF.
 */
class ReportCsvExporter
{
    private function name(array|object $row): string
    {
        $row = (array) $row;

        return app()->getLocale() === 'ar'
            ? ($row['name_ar'] ?? $row['name_en'] ?? $row['code'] ?? '')
            : ($row['name_en'] ?? $row['name_ar'] ?? $row['code'] ?? '');
    }

    /** @return array{headers: array<int,string>, rows: array<int, array<int, string|float>>} */
    public function trialBalance(array $report): array
    {
        $rows = [];
        foreach ($report['rows'] as $r) {
            $r = (array) $r;
            $rows[] = [$r['code'], $this->name($r), __("admin.reports.csv.account_types.{$r['type']}"),
                round((float) $r['debit_balance'], 2), round((float) $r['credit_balance'], 2)];
        }
        // A totals line, so the exported file self-checks (debit total must equal credit total).
        $rows[] = ['', __('admin.reports.csv.total'), '', round((float) $report['total_debit'], 2), round((float) $report['total_credit'], 2)];

        return [
            'headers' => [
                __('admin.reports.csv.account_code'), __('admin.reports.csv.account'),
                __('admin.reports.csv.type'), __('admin.reports.csv.debit'), __('admin.reports.csv.credit'),
            ],
            'rows' => $rows,
        ];
    }

    /** @return array{headers: array<int,string>, rows: array<int, array<int, string|float>>} */
    public function incomeStatement(array $report): array
    {
        // The same sections, in the same order, that the screen and the PDF print
        // ({@see IncomeStatementLayout}) — including the net-operating-income line when the chart
        // has anything below it. An export laid out differently from the screen it was taken from
        // is the drift this project has shipped once already.
        $sections = IncomeStatementLayout::sections($report);

        // The last section IS the bottom line, and `sectioned()` prints that separately.
        $net = array_pop($sections);

        return $this->sectioned(
            array_map(
                fn (array $s): array => [$s['label'], $s['rows'], $s['total'], $s['total_label']],
                $sections,
            ),
            netLabel: $net['label'],
            netAmount: $net['total'],
        );
    }

    /** @return array{headers: array<int,string>, rows: array<int, array<int, string|float>>} */
    public function balanceSheet(array $report): array
    {
        return $this->sectioned([
            [__('admin.reports.csv.assets'), $report['assets'], (float) $report['total_assets']],
            [__('admin.reports.csv.liabilities'), $report['liabilities'], (float) $report['total_liabilities']],
            [__('admin.reports.csv.equity'), $report['equity'], (float) $report['total_equity']],
        ], netLabel: __('admin.reports.csv.net_income'), netAmount: (float) $report['net_income']);
    }

    /** @return array{headers: array<int,string>, rows: array<int, array<int, string|float>>} */
    public function cashFlow(array $report): array
    {
        // Operating cash flow starts from net income, then the non-cash add-backs — so its subtotal
        // (operating_total) reconciles. Prepend net income as the section's first line.
        $operating = collect([[
            'code' => '', 'name_en' => 'Net income', 'name_ar' => 'صافي الدخل',
            'amount' => (float) $report['net_income'],
        ]])->concat(collect($report['adjustments']));

        return $this->sectioned([
            [__('admin.reports.csv.operating'), $operating, (float) $report['operating_total']],
            [__('admin.reports.csv.investing'), $report['investing'], (float) $report['investing_total']],
            [__('admin.reports.csv.financing'), $report['financing'], (float) $report['financing_total']],
            // Activities, not chart branches — see `CashFlow::groupStatements()`.
        ], netLabel: __('admin.reports.csv.net_change'), netAmount: (float) $report['net_change'], grouped: false);
    }

    /**
     * A statement made of named sections (revenue/expense, assets/liabilities/equity, …). Each row is
     * [section, account code, account name, amount]; each section closes with a subtotal, then a
     * final net line — so the CSV reads exactly like the on-screen statement.
     *
     * A section may name its own total line (the income statement's "Total operating expenses",
     * and its mid-statement NET OPERATING INCOME row); the others fall back to a plain "Subtotal".
     *
     * @param  array<int, array{0:string,1:Collection|iterable,2:float,3?:?string}>  $sections
     * @return array{headers: array<int,string>, rows: array<int, array<int, string|float>>}
     */
    private function sectioned(array $sections, string $netLabel, float $netAmount, bool $grouped = true): array
    {
        $locale = app()->getLocale();
        $rows = [];

        foreach ($sections as $section) {
            [$label, $lines, $subtotal] = $section;
            $subtotalLabel = $section[3] ?? __('admin.reports.csv.subtotal');
            // The chart's own subtotals (EG-28) — the same ones the screen and the PDF print, from
            // the same helper. A statement whose export is laid out differently from the screen is
            // the failure this ticket's sibling (EG-36) shipped once already.
            $groups = StatementGroups::for(collect($lines)->map(fn ($line): array => (array) $line)->all());
            $showGroups = $grouped && StatementGroups::worthShowing($groups);

            foreach ($groups as $group) {
                foreach ($group['rows'] as $line) {
                    $rows[] = [$label, $line['code'] ?? '', $this->name($line), round((float) ($line['amount'] ?? 0), 2)];
                }

                if (! $showGroups || ! $group['show_subtotal']) {
                    continue;
                }

                $rows[] = [$label, '', __('admin.reports.group_subtotal', [
                    'group' => $locale === 'ar' ? $group['name_ar'] : $group['name_en'],
                ]), round($group['total'], 2)];
            }

            $rows[] = [$label, '', $subtotalLabel, round($subtotal, 2)];
        }
        $rows[] = ['', '', $netLabel, round($netAmount, 2)];

        return [
            'headers' => [
                __('admin.reports.csv.section'), __('admin.reports.csv.account_code'),
                __('admin.reports.csv.account'), __('admin.reports.csv.amount'),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * General ledger for one account: every posted line in date order with a running balance —
     * the raw transaction detail an accountant reconciles against.
     *
     * @param  array{opening: float, lines: Collection, closing: float}  $statement
     * @return array{headers: array<int,string>, rows: array<int, array<int, string|float>>}
     */
    public function generalLedger(array $statement): array
    {
        $isAr = app()->getLocale() === 'ar';
        $rows = [['', '', __('admin.reports.csv.opening_balance'), '', '', round((float) $statement['opening'], 2)]];

        foreach ($statement['lines'] as $line) {
            $line = (array) $line;
            $rows[] = [
                $line['entry_date'],
                $line['entry_number'],
                // Through the same seam the screen uses (EG-36). Reading the prose columns here
                // would let the exported CSV and the general ledger it was exported FROM disagree
                // about the same line the moment a narrative's wording changed.
                JournalNarrative::resolve(
                    $line['description_key'] ?? null,
                    is_string($line['description_data'] ?? null)
                        ? json_decode((string) $line['description_data'], true)
                        : ($line['description_data'] ?? null),
                    $line['description_en'] ?? null,
                    $line['description_ar'] ?? null,
                    $isAr ? 'ar' : 'en',
                ),
                round((float) $line['debit'], 2),
                round((float) $line['credit'], 2),
                round((float) $line['running_balance'], 2),
            ];
        }
        $rows[] = ['', '', __('admin.reports.csv.closing_balance'), '', '', round((float) $statement['closing'], 2)];

        return [
            'headers' => [
                __('admin.reports.csv.date'), __('admin.reports.csv.entry'), __('admin.reports.csv.description'),
                __('admin.reports.csv.debit'), __('admin.reports.csv.credit'), __('admin.reports.csv.balance'),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * AR aging drill-down for a bucket — the collections worklist: which tenant owes what, how late.
     *
     * @param  Collection<int, Invoice>  $invoices
     * @return array{headers: array<int,string>, rows: array<int, array<int, string|float>>}
     */
    public function arAging(Collection $invoices): array
    {
        $rows = [];
        foreach ($invoices as $invoice) {
            $daysOverdue = max(0, (int) $invoice->due_date->diffInDays(now(), false));
            $rows[] = [
                $invoice->number,
                // data_get null-walks the chained relations without tripping larastan's
                // belongsTo-non-null assumption (a soft-deleted tenant/unit reads as '—').
                data_get($invoice, 'tenant.name', '—'),
                data_get($invoice, 'lease.unit.code', '—'),
                $invoice->due_date->toDateString(),
                $daysOverdue,
                round((float) $invoice->balance, 2),
            ];
        }

        return [
            'headers' => [
                __('admin.reports.csv.invoice'), __('admin.reports.csv.tenant'), __('admin.reports.csv.unit'),
                __('admin.reports.csv.due_date'), __('admin.reports.csv.days_overdue'), __('admin.reports.csv.balance'),
            ],
            'rows' => $rows,
        ];
    }
}
