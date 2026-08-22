<?php

namespace App\Support;

use App\Models\LedgerAccount;

/**
 * The subtotals a financial statement is read by, taken from the CHART's own hierarchy (EG-28).
 *
 * Statements listed every moving account flat under its type, so a balance sheet was forty-odd leaf
 * lines with one total at the bottom — and the summary accounts the chart already models
 * (`is_postable = false`) appeared nowhere. `parent_id` was read by no report at all.
 *
 * An accountant reads a statement by section: current versus non-current, operating revenue versus
 * other income versus sales returns. The chart already says which is which; nothing asked it.
 *
 * ## The group is the highest ancestor BELOW the root
 *
 * Read off `parent_id`, not off the code. `LedgerAccount::saving` does derive that parent from the
 * code prefix, so the two agree here — but reading the TREE means this works at any depth and any
 * width without knowing where one level of the numbering ends and the next begins, which is the
 * assumption the cash-flow statement had to be freed of earlier in this same ticket.
 *
 * An account with no parent belongs to no group: it either IS a root, or its code matched no
 * shorter code and the chart never placed it. Both render ungrouped, after the grouped rows and
 * with no subtotal — they still print, and they still count toward the section total. So does the
 * balance sheet's synthetic "net income for the period" line, which has no account at all.
 *
 * ## One helper, three renderers
 *
 * The screen, the CSV and the PDF each build a statement their own way, and a grouping written into
 * one of them is a statement that disagrees with its own export the first time anything changes —
 * which is exactly what happened to the general ledger's narrative before EG-36. They all call this.
 */
final class StatementGroups
{
    private const MEMO = 'atriom.statement_groups.chart';

    /**
     * Group a section's rows, in chart order.
     *
     * Returns an ordered list of groups, each with the summary account's own names and its rows.
     * `code` is null for the ungrouped bucket, which carries any row with no account behind it.
     *
     * @param  iterable<int, array<string, mixed>>  $rows
     * @param  string  $amountKey  which key holds the figure — `amount` on a plain statement,
     *                             `current` on the comparative one, whose rows carry two
     * @return list<array{code: ?string, name_en: string, name_ar: string, rows: list<array<string, mixed>>, total: float, show_subtotal: bool}>
     */
    public static function for(iterable $rows, string $amountKey = 'amount'): array
    {
        $chart = self::chart();
        $groups = [];
        $ungrouped = [];

        foreach ($rows as $row) {
            $ancestor = self::topBelowRoot($chart, self::accountId($row));

            if ($ancestor === null) {
                $ungrouped[] = $row;

                continue;
            }

            $key = $ancestor['code'];

            $groups[$key] ??= [
                'code' => $ancestor['code'],
                'name_en' => $ancestor['name_en'],
                'name_ar' => $ancestor['name_ar'],
                'rows' => [],
                'total' => 0.0,
                'show_subtotal' => false,
            ];

            $groups[$key]['rows'][] = $row;
            $groups[$key]['total'] = round($groups[$key]['total'] + (float) ($row[$amountKey] ?? 0), 2);

            // A one-row group is its own subtotal, and printing the same figure again under a
            // second name reads as an error — the same rule `worthShowing()` applies to a section,
            // one level down. "Share capital 500,000 / Total Capital 500,000" is four lines for two
            // facts, and the row's own name says more than the group heading repeating it.
            $groups[$key]['show_subtotal'] = count($groups[$key]['rows']) > 1;
        }

        // Chart order — the order the accountant numbered them, which is the order a statement is
        // read in, and not the order the aggregate happened to return rows in.
        //
        // Sorted on the code STRING with a natural compare, deliberately not `ksort`: PHP coerces a
        // numeric array key to an int, so a chart mixing `9` with `11` would sort one way and a
        // chart using `A1` another, off an implementation detail nothing here states.
        $ordered = array_values($groups);
        usort($ordered, fn (array $a, array $b): int => strnatcmp($a['code'], $b['code']));

        if ($ungrouped !== []) {
            $ordered[] = [
                'code' => null,
                'name_en' => '',
                'name_ar' => '',
                'rows' => $ungrouped,
                'total' => round(array_sum(array_map(fn ($r) => (float) ($r[$amountKey] ?? 0), $ungrouped)), 2),
                // Nothing to name, so nothing to subtotal.
                'show_subtotal' => false,
            ];
        }

        return $ordered;
    }

    /**
     * Is grouping worth showing for this section?
     *
     * One group means its subtotal equals the section total, and a statement that prints the same
     * figure twice under two names reads as an error. So a single-group section renders exactly as
     * it did before.
     *
     * @param  list<array<string, mixed>>  $groups
     */
    public static function worthShowing(array $groups): bool
    {
        return count(array_filter($groups, fn (array $g): bool => $g['code'] !== null)) > 1;
    }

    /**
     * Which chart account is this row?
     *
     * By id where the report gives one, and by CODE where it does not — the comparative income
     * statement works in labels and codes because it compares two periods rather than reading the
     * chart. Both are the same fact asked two ways, and answering only the first would have grouped
     * a plain income statement and left its comparative twin flat, which is the screen disagreeing
     * with itself over one checkbox.
     *
     * @param  array<string, mixed>  $row
     */
    private static function accountId(array $row): ?int
    {
        if (is_numeric($row['account_id'] ?? null)) {
            return (int) $row['account_id'];
        }

        $code = $row['code'] ?? null;

        return $code === null || $code === '' ? null : (self::codeIndex()[(string) $code] ?? null);
    }

    /**
     * The account's highest ancestor below the root of its tree.
     *
     * @param  array<int, array{parent_id: ?int, code: string, name_en: string, name_ar: string}>  $chart
     * @return array{code: string, name_en: string, name_ar: string}|null
     */
    private static function topBelowRoot(array $chart, mixed $accountId): ?array
    {
        $id = is_numeric($accountId) ? (int) $accountId : null;

        if ($id === null || ! isset($chart[$id])) {
            return null;
        }

        $node = $chart[$id];

        // A root has no group of its own to sit under — it IS the section.
        if ($node['parent_id'] === null) {
            return null;
        }

        // Walk up while the parent still has a parent; stop on the step below the root. Bounded by
        // the chart's size so a cycle from a hand-edited row cannot hang a report.
        $guard = 0;

        while (isset($chart[$node['parent_id']]) && $chart[$node['parent_id']]['parent_id'] !== null && $guard++ < 32) {
            $node = $chart[$node['parent_id']];
        }

        return ['code' => $node['code'], 'name_en' => $node['name_en'], 'name_ar' => $node['name_ar']];
    }

    /**
     * code → id, over the same memoised chart. No second query.
     *
     * @return array<string, int>
     */
    private static function codeIndex(): array
    {
        $index = [];

        foreach (self::chart() as $id => $node) {
            $index[$node['code']] = $id;
        }

        return $index;
    }

    /**
     * The whole chart as a lookup, memoised per request.
     *
     * One query for a statement of any size. Memoised through the container rather than a static,
     * because a `queue:work` daemon outlives the request and a scheduled delivery would otherwise
     * render tomorrow's statement against yesterday's chart.
     *
     * @return array<int, array{parent_id: ?int, code: string, name_en: string, name_ar: string}>
     */
    private static function chart(): array
    {
        if (app()->has(self::MEMO)) {
            return app(self::MEMO);
        }

        $chart = LedgerAccount::query()
            ->withTrashed()
            ->get(['id', 'parent_id', 'code', 'name_en', 'name_ar'])
            ->keyBy('id')
            ->map(fn (LedgerAccount $a): array => [
                'parent_id' => $a->parent_id === null ? null : (int) $a->parent_id,
                'code' => (string) $a->code,
                'name_en' => (string) $a->name_en,
                'name_ar' => (string) $a->name_ar,
            ])
            ->all();

        app()->instance(self::MEMO, $chart);

        return $chart;
    }
}
