<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * The SHAPE of an income statement — what its sections are and where the net lines fall between
 * them — stated once, for every way this app renders one.
 *
 * `StatementGroups` answers what the subtotals INSIDE a section are; this answers what the sections
 * are. It exists for the same reason and states the same rule: renderers each building the statement
 * their own way produce a statement that disagrees with its own export the first time anything
 * changes, which is a failure this codebase has shipped twice (EG-36's narrative, and the
 * comparative statement's blank account names).
 *
 * ## Two shapes, and the chart decides which
 *
 * With nothing classified below the net-operating-income line, the statement has exactly the three
 * sections it always had — revenue, expenses, net profit. Add a below-the-line account and it takes
 * the property shape:
 *
 *     Operating revenue · Operating expenses · NET OPERATING INCOME · Other income · Other expenses · Net profit
 *
 * That is not a toggle anybody sets. An unclassified chart makes NOI EQUAL net profit, and printing
 * one figure twice under two names reads as an error rather than as information — the rule
 * {@see StatementGroups::worthShowing()} already applies one level down. So the statement grows the
 * line the moment the line means something, and an install that has never opened the chart screen
 * sees precisely what it saw before.
 *
 * ## Four readings, one shape
 *
 * {@see shape()} is the structure alone, with no figures in it. Four readings attach different data
 * to it and none may disagree with the others about what the sections are:
 *
 * - {@see sections()} — one period. The screen's plain reading, the CSV and the PDF.
 * - the COMPARATIVE reading (`IncomeStatement::comparativeLayout()`), which adds prior/change columns.
 * - the SPREAD reading (`StatementSpread`), which adds one column per span — month-and-year-to-date,
 *   or the twelve months of a year.
 *
 * The last two cannot call `sections()`: their row sets are a UNION across spans — an account with
 * activity in March and none in May, or one that ran last period and stopped — so they select rows by
 * what those rows CARRY (`section` + `statement_section`) rather than reading the report's own
 * collections. That is why `shape()` names both, and why a part records which totals key each
 * reading should look its figure up under.
 *
 * ## The subtotals INSIDE a section still come from the chart, and can read oddly
 *
 * `StatementGroups` groups a section's rows by their highest chart ancestor below the root, and this
 * split cuts ACROSS that: the shipped chart keeps depreciation at `51107`, inside `51 Operating
 * Expenses`, while classifying it below the line. So a below-the-line section can in principle print
 * a group heading reading "Total Operating Expenses". It does not on the shipped chart — that group
 * has exactly one row there, and a one-row group prints no subtotal — but it would on a chart where
 * several accounts under one branch are classified differently. The heading would still be TRUE
 * about the chart, and the answer if it ever reads wrong is the chart: give the below-the-line
 * accounts their own branch. Grouping is deliberately not switched off here, because the sections
 * really are chart branches for everything except that one account.
 *
 * ## A NET part carries no rows
 *
 * NOI and net profit are figures the parts above them foot to, not lists of accounts. They are still
 * parts rather than a special case bolted onto the end, because every renderer already knows how to
 * print a section and none of them needed to learn a second shape.
 */
final class IncomeStatementLayout
{
    /**
     * The structure of the statement, with no figures attached.
     *
     * @param  bool  $hasBelowTheLine  does anything sit below the NOI line, on EITHER side of
     *                                 whatever is being compared or spread?
     * @return list<array{key: string, label: string, total_label: string, section: ?string,
     *     statement_section: ?string, rows_key: ?string, report_total_key: ?string, totals_key: string,
     *     is_net: bool, optional: bool}>
     */
    public static function shape(bool $hasBelowTheLine): array
    {
        if (! $hasBelowTheLine) {
            return [
                // `statement_section` is null here, meaning "do not narrow". On an unclassified
                // chart the statement has one revenue section and one expense section, and
                // narrowing would give a stray row nowhere to print — a line silently missing from
                // a financial statement is the one failure worse than a wrong layout.
                self::part('revenue', 'revenue', 'total_revenue', 'revenue', null, 'revenue', 'total_revenue'),
                self::part('expense', 'expenses', 'total_expenses', 'expense', null, 'expense', 'total_expense'),
                self::net('net_profit', 'net', 'net_profit'),
            ];
        }

        return [
            self::part('operating_revenue', 'operating_revenue', 'total_operating_revenue', 'revenue', StatementSection::OPERATING, 'operating_revenue', 'total_operating_revenue'),
            self::part('operating_expense', 'operating_expenses', 'total_operating_expenses', 'expense', StatementSection::OPERATING, 'operating_expense', 'total_operating_expense'),
            // The line the whole shape exists for. It sits directly under what it is made of, which
            // is where an owner, a valuer and a lender all look for it first.
            self::net('net_operating_income', 'noi', 'net_operating_income'),
            // Each below-the-line part only when it HAS something. A mall with interest and no
            // disposal gain would otherwise print an "Other income" heading over a 0.00 — a section
            // that says nothing, which is the same noise a one-row subtotal is.
            self::part('other_revenue', 'other_income', 'total_other_income', 'revenue', StatementSection::NON_OPERATING, 'other_revenue', 'total_other_revenue', optional: true),
            self::part('other_expense', 'other_expenses', 'total_other_expenses', 'expense', StatementSection::NON_OPERATING, 'other_expense', 'total_other_expense', optional: true),
            self::net('net_profit', 'net', 'net_profit'),
        ];
    }

    /**
     * The sections of a SINGLE-PERIOD income statement, with their rows and totals attached.
     *
     * @param  array<string, mixed>  $report  the array `LedgerReportService::incomeStatement()` returns
     * @return list<array{key: string, label: string, rows: Collection, total: float, total_label: string, is_net: bool}>
     */
    public static function sections(array $report): array
    {
        $sections = [];

        foreach (self::shape((bool) ($report['has_below_the_line'] ?? false)) as $part) {
            $rows = $part['is_net'] ? collect() : collect($report[$part['rows_key']] ?? []);

            if ($part['optional'] && $rows->isEmpty()) {
                continue;
            }

            $sections[] = [
                'key' => $part['key'],
                'label' => $part['label'],
                'rows' => $rows,
                'total' => round((float) ($report[$part['report_total_key']] ?? 0), 2),
                // A net part's heading and its total line are the same words, so a renderer prints
                // one row rather than a heading over an empty list.
                'total_label' => $part['is_net'] ? $part['label'] : $part['total_label'],
                'is_net' => $part['is_net'],
            ];
        }

        return $sections;
    }

    /**
     * @return array{key: string, label: string, total_label: string, section: ?string,
     *     statement_section: ?string, rows_key: ?string, report_total_key: ?string, totals_key: string,
     *     is_net: bool, optional: bool}
     */
    private static function part(string $key, string $labelKey, string $totalLabelKey, string $section, ?string $statementSection, string $totalsKey, string $reportTotalKey, bool $optional = false): array
    {
        return [
            'key' => $key,
            'label' => __('admin.reports.'.$labelKey),
            'total_label' => __('admin.reports.'.$totalLabelKey),
            'section' => $section,
            'statement_section' => $statementSection,
            'rows_key' => $key,
            'report_total_key' => $reportTotalKey,
            'totals_key' => $totalsKey,
            'is_net' => false,
            'optional' => $optional,
        ];
    }

    /**
     * @return array{key: string, label: string, total_label: string, section: ?string,
     *     statement_section: ?string, rows_key: ?string, report_total_key: ?string, totals_key: string,
     *     is_net: bool, optional: bool}
     */
    private static function net(string $labelKey, string $totalsKey, string $reportTotalKey): array
    {
        return [
            'key' => $labelKey,
            'label' => __('admin.reports.'.$labelKey),
            'total_label' => __('admin.reports.'.$labelKey),
            'section' => null,
            'statement_section' => null,
            'rows_key' => null,
            'report_total_key' => $reportTotalKey,
            'totals_key' => $totalsKey,
            'is_net' => true,
            'optional' => false,
        ];
    }
}
