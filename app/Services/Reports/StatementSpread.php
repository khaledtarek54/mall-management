<?php

namespace App\Services\Reports;

use App\Services\Accounting\BudgetService;
use App\Services\Accounting\LedgerReportService;
use App\Support\StatementSection;
use Carbon\CarbonImmutable;

/**
 * An income statement read across SEVERAL columns at once.
 *
 * A single-period P&L answers "what happened". It cannot answer the two questions an operator
 * actually runs a mall on:
 *
 * - **"Where are we against the year?"** — the month beside the year to date, which is the layout
 *   Yardi's income statement opens in and the one an owner asks for by name.
 * - **"Is anything running away?"** — the twelve months of the year side by side, where a cost
 *   creeping up shows as a shape rather than as twelve reports somebody has to remember.
 *
 * Both are the same feature: a statement with N amount columns instead of one. That is the whole
 * reason this is one class — building month-and-year-to-date and the twelve-month spread separately
 * would be two definitions of what a column of an income statement IS.
 *
 * ## A GROUP is what the caller asks for; a SPAN is a column
 *
 * The caller names date ranges ("Mar 2026", "Year to date"). With a comparison basis, each group
 * becomes THREE columns — actual, the comparison, and the variance between them — which is exactly
 * how Yardi prints its month-and-YTD statement. Without one it is a single column per group. The
 * caller never assembles that itself, so a screen cannot end up offering a variance column it has no
 * comparison for.
 *
 * ## Built ON the existing services, never beside them
 *
 * Every figure comes from `LedgerReportService::incomeStatement()` or, for a budget column,
 * `BudgetService::asIncomeStatement()` — the same two definitions of revenue and expense that the
 * single-period statement uses, queried once per column. A second implementation would drift, and
 * the drift would show up as a variance nobody could explain, which is the failure a spread exists
 * to prevent.
 *
 * ## The row set is a UNION, and that is load-bearing
 *
 * An account with activity in March and none in May must still print a row, or the May column would
 * silently have no line to put its zero on and the spread would be ragged. So rows are merged across
 * every column and keyed on the account, with a missing column reading 0.00 — the same rule
 * `ComparativeStatementService` follows for the account that ran last period and stopped, and the
 * reason neither of them can read the report's own collections and must select on what a row carries.
 */
class StatementSpread
{
    public function __construct(
        private LedgerReportService $reports,
        private BudgetService $budgets,
    ) {}

    /** Suffix of the column holding a group's comparison figure. */
    public const COMPARISON_SUFFIX = '__cmp';

    /** Suffix of the column holding a group's variance — actual less comparison. */
    public const VARIANCE_SUFFIX = '__var';

    /**
     * @param  list<array{key: string, label: string, from: CarbonImmutable, to: CarbonImmutable}>  $groups
     * @param  string|null  $basis  a `ComparativeStatementService` basis, adding a comparison and a
     *                              variance column to every group; null for actuals alone
     * @return array{
     *     spans: list<array{key: string, label: string, kind: string, group: string}>,
     *     rows: list<array<string, mixed>>,
     *     totals: array<string, array<string, float>>,
     *     has_below_the_line: bool,
     * }
     */
    public function incomeStatement(array $groups, ?array $assetIds = null, ?string $basis = null): array
    {
        $basis = in_array($basis, ComparativeStatementService::BASES, true) ? $basis : null;

        $spans = [];
        $rows = [];
        $totals = [];
        $hasBelowTheLine = false;

        foreach ($groups as $group) {
            $spans[] = ['key' => $group['key'], 'label' => $group['label'], 'kind' => 'actual', 'group' => $group['key']];

            $report = $this->reports->incomeStatement($assetIds, $group['from'], $group['to']);
            $hasBelowTheLine = $hasBelowTheLine || (bool) $report['has_below_the_line'];
            $this->absorb($report, $group['key'], $rows, $totals);

            if ($basis === null) {
                continue;
            }

            $comparisonKey = $group['key'].self::COMPARISON_SUFFIX;
            $varianceKey = $group['key'].self::VARIANCE_SUFFIX;

            $spans[] = ['key' => $comparisonKey, 'label' => $this->comparisonLabel($group['label'], $basis), 'kind' => 'comparison', 'group' => $group['key']];
            $spans[] = ['key' => $varianceKey, 'label' => __('admin.reports.variance'), 'kind' => 'variance', 'group' => $group['key']];

            $comparison = $this->comparisonReport($group, $assetIds, $basis);
            $hasBelowTheLine = $hasBelowTheLine || (bool) ($comparison['has_below_the_line'] ?? false);
            $this->absorb($comparison, $comparisonKey, $rows, $totals);

            // Derived, never queried: a variance that came from anywhere but the two columns beside
            // it could disagree with the subtraction a reader does in their head, which is the one
            // thing a variance column may not do.
            foreach ($rows as &$row) {
                $row['amounts'][$varianceKey] = round(($row['amounts'][$group['key']] ?? 0.0) - ($row['amounts'][$comparisonKey] ?? 0.0), 2);
            }
            unset($row);

            foreach ($totals as $bucket => $figures) {
                $totals[$bucket][$varianceKey] = round(($figures[$group['key']] ?? 0.0) - ($figures[$comparisonKey] ?? 0.0), 2);
            }
        }

        // Chart order — the order the accountant numbered the accounts, which is the order a
        // statement is read in and not the order the first column happened to produce rows in.
        usort($rows, fn (array $a, array $b): int => strnatcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? '')));

        // Every row carries every column, so a renderer never has to ask whether a cell exists and a
        // missing figure can never render as a blank where 0.00 is the truth.
        //
        // REBUILT in span order rather than filled in place: a row first seen in February would
        // otherwise carry February before January, because a missing key is appended. Nothing here
        // renders from that order — every renderer looks each cell up by key — but a payload whose
        // column order depends on which month an account happened to trade in is one the next reader
        // cannot rely on, and this is the array a CSV and an API would both hand onward.
        $keys = array_column($spans, 'key');
        $ordered = fn (array $figures): array => array_combine(
            $keys,
            array_map(fn (string $key): float => round((float) ($figures[$key] ?? 0), 2), $keys),
        );

        foreach ($rows as &$row) {
            $row['amounts'] = $ordered($row['amounts']);
        }
        unset($row);

        foreach ($totals as $bucket => $figures) {
            $totals[$bucket] = $ordered($figures);
        }

        return [
            'spans' => $spans,
            'rows' => array_values($rows),
            'totals' => $totals,
            'has_below_the_line' => $hasBelowTheLine,
        ];
    }

    /**
     * Fold one column's income statement into the merged row set and totals.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<string, array<string, float>>  $totals
     */
    private function absorb(array $report, string $key, array &$rows, array &$totals): void
    {
        foreach (['revenue', 'expense'] as $section) {
            foreach ($report[$section] as $line) {
                $line = (array) $line;

                // Keyed on the account CODE, which is what survives across periods — an id would
                // too, but the budget's rows and the ledger's rows are two different reads of the
                // same chart and the code is the identity they share.
                $id = $section.':'.($line['code'] ?? $line['account_id'] ?? '');

                $rows[$id] ??= [
                    'code' => $line['code'] ?? null,
                    'name_en' => (string) ($line['name_en'] ?? ''),
                    'name_ar' => (string) ($line['name_ar'] ?? ''),
                    'account_id' => isset($line['account_id']) ? (int) $line['account_id'] : null,
                    'section' => $section,
                    'statement_section' => StatementSection::for($line['statement_section'] ?? null, $section),
                    'amounts' => [],
                ];

                $rows[$id]['amounts'][$key] = round((float) ($line['amount'] ?? 0), 2);
            }
        }

        // The buckets `IncomeStatementLayout::shape()` names as its `totals_key`s, so a renderer
        // looks a section's figure up under one name whichever reading it is drawing.
        $totals['revenue'][$key] = (float) $report['total_revenue'];
        $totals['expense'][$key] = (float) $report['total_expense'];
        $totals['net'][$key] = (float) $report['net_profit'];
        $totals['operating_revenue'][$key] = (float) $report['total_operating_revenue'];
        $totals['operating_expense'][$key] = (float) $report['total_operating_expense'];
        $totals['noi'][$key] = (float) $report['net_operating_income'];
        $totals['other_revenue'][$key] = (float) $report['total_other_revenue'];
        $totals['other_expense'][$key] = (float) $report['total_other_expense'];
    }

    /**
     * The figures one group is measured against.
     *
     * The budget compares against the group's OWN dates — a plan for March is not a plan for
     * February — while a prior period or prior year moves the span, using the same derivation
     * `ComparativeStatementService` states so that a spread and a plain comparison can never
     * disagree about which months they are looking at.
     *
     * @param  array{key: string, label: string, from: CarbonImmutable, to: CarbonImmutable}  $group
     * @return array<string, mixed>
     */
    private function comparisonReport(array $group, ?array $assetIds, string $basis): array
    {
        if ($basis === ComparativeStatementService::BUDGET) {
            return $this->budgets->asIncomeStatement($group['from'], $group['to'], $assetIds);
        }

        [$from, $to] = ComparativeStatementService::priorSpan($group['from'], $group['to'], $basis);

        return $this->reports->incomeStatement($assetIds, $from, $to);
    }

    private function comparisonLabel(string $groupLabel, string $basis): string
    {
        return match ($basis) {
            ComparativeStatementService::BUDGET => __('admin.reports.spread_budget', ['group' => $groupLabel]),
            ComparativeStatementService::PRIOR_YEAR => __('admin.reports.spread_prior_year', ['group' => $groupLabel]),
            default => __('admin.reports.spread_prior_period', ['group' => $groupLabel]),
        };
    }
}
