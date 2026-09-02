<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * The ORDER an income statement is read in, once — for the screen, the CSV and the PDF.
 *
 * `StatementGroups` answers what the subtotals INSIDE a section are; this answers what the sections
 * are and where the net lines fall between them. It exists for the same reason and states the same
 * rule: three renderers each building the statement their own way is a statement that disagrees with
 * its own export the first time anything changes, which is a failure this codebase has shipped twice
 * (EG-36's narrative, and the comparative statement's blank account names).
 *
 * ## Two shapes, and the chart decides which
 *
 * With nothing classified below the net-operating-income line, this returns exactly the three
 * sections the statement always had — revenue, expenses, net profit. Add a below-the-line account
 * and it returns the property shape:
 *
 *     Operating revenue · Operating expenses · NET OPERATING INCOME · Other income · Other expenses · Net profit
 *
 * That is not a toggle anybody sets. An unclassified chart makes NOI EQUAL net profit, and printing
 * one figure twice under two names reads as an error rather than as information — the rule
 * {@see StatementGroups::worthShowing()} already applies one level down. So the statement grows the
 * line the moment the line means something, and an install that has never opened the chart screen
 * sees precisely what it saw before.
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
 * ## A NET section carries no rows
 *
 * NOI and net profit are figures the sections above them foot to, not lists of accounts. They are
 * still sections rather than a special case bolted onto the end, because all three renderers already
 * know how to print a section and none of them needed to learn a second shape — the page had already
 * done this for net profit, and this is that idea generalised.
 */
final class IncomeStatementLayout
{
    /**
     * The sections of an income statement, in reading order.
     *
     * @param  array<string, mixed>  $report  the array `LedgerReportService::incomeStatement()` returns
     * @return list<array{key: string, label: string, rows: Collection, total: float, total_label: string, is_net: bool}>
     */
    public static function sections(array $report): array
    {
        if (! ($report['has_below_the_line'] ?? false)) {
            return [
                self::section('revenue', 'revenue', $report['revenue'], (float) $report['total_revenue'], 'total_revenue'),
                self::section('expense', 'expenses', $report['expense'], (float) $report['total_expense'], 'total_expenses'),
                self::net('net_profit', (float) $report['net_profit']),
            ];
        }

        $sections = [
            self::section('operating_revenue', 'operating_revenue', $report['operating_revenue'], (float) $report['total_operating_revenue'], 'total_operating_revenue'),
            self::section('operating_expense', 'operating_expenses', $report['operating_expense'], (float) $report['total_operating_expense'], 'total_operating_expenses'),
            // The line the whole shape exists for. It sits directly under what it is made of, which
            // is where an owner, a valuer and a lender all look for it first.
            self::net('net_operating_income', (float) $report['net_operating_income']),
        ];

        // Each below-the-line section only when it HAS something. A mall with interest and no
        // disposal gain would otherwise print an "Other income" heading over a 0.00 — a section that
        // says nothing, which is the same noise a one-row subtotal is.
        if (collect($report['other_revenue'])->isNotEmpty()) {
            $sections[] = self::section('other_revenue', 'other_income', $report['other_revenue'], (float) $report['total_other_revenue'], 'total_other_income');
        }

        if (collect($report['other_expense'])->isNotEmpty()) {
            $sections[] = self::section('other_expense', 'other_expenses', $report['other_expense'], (float) $report['total_other_expense'], 'total_other_expenses');
        }

        $sections[] = self::net('net_profit', (float) $report['net_profit']);

        return $sections;
    }

    /**
     * @param  iterable<int, mixed>  $rows
     * @return array{key: string, label: string, rows: Collection, total: float, total_label: string, is_net: bool}
     */
    private static function section(string $key, string $labelKey, iterable $rows, float $total, string $totalLabelKey): array
    {
        return [
            'key' => $key,
            'label' => __('admin.reports.'.$labelKey),
            'rows' => collect($rows),
            'total' => round($total, 2),
            'total_label' => __('admin.reports.'.$totalLabelKey),
            'is_net' => false,
        ];
    }

    /**
     * A figure the sections above it foot to. Its heading and its total line are the same words, so
     * the renderers print one row rather than a heading over an empty list.
     *
     * @return array{key: string, label: string, rows: Collection, total: float, total_label: string, is_net: bool}
     */
    private static function net(string $labelKey, float $total): array
    {
        return [
            'key' => $labelKey,
            'label' => __('admin.reports.'.$labelKey),
            'rows' => collect(),
            'total' => round($total, 2),
            'total_label' => __('admin.reports.'.$labelKey),
            'is_net' => true,
        ];
    }
}
