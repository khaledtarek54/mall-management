<?php

namespace App\Support;

/**
 * **A statement read as the chart's own tree** (meeting 2026-09-02, point 19 — *"the trial balance
 * should be a tree: the parent general account, and under it the accounts, hide and show"*).
 *
 * The trial balance listed every postable leaf flat, ordered by code — sixty rows an accountant
 * reads by scanning for the group they want. The chart is five levels deep (`1 · 11 · 111 · 11101 ·
 * 11101001`), every summary account already exists as a row with `is_postable = false`, and
 * `parent_id` is derived and self-healing (EG-28) — so the hierarchy was there and no report read
 * it past the top group. Financial reports run at an account-tree level everywhere this project
 * benchmarks: a roll-up per branch, folded or unfolded at will, closing to the same totals.
 *
 * ## What a node carries
 *
 * Every ancestor of a leaf that is ON the statement is a node, with each money column summed over
 * the leaves beneath it — the six columns of a trial balance stay six, debit and credit sides
 * summed SEPARATELY, so a folded branch foots to the grand totals exactly as the leaves it hides
 * did. (Netting a branch to one side would print a figure the totals row does not contain.) A
 * leaf keeps its own row and its own figures; nothing is re-derived. An account the chart never
 * placed (no `parent_id`) stands at the root beside the real roots and still counts.
 *
 * Depth-first in chart order, siblings compared as code STRINGS — the order
 * `LedgerReportService::trialBalance()` lists its rows in and the order the database gives the
 * general ledger, so the leaves stand in the tree exactly as they stood in the flat listing (a
 * natural compare, `StatementGroups`' choice for a statement's top groups, would put `9` before
 * `10` on a mixed-width imported chart and after it in the ledger).
 *
 * ## One helper, three renderers
 *
 * The screen opens folded to the roots and unfolds on click; the PDF prints the SAME nodes at the
 * same fold, because a printed copy is a picture of what was looked at; the CSV prints the WHOLE
 * tree with a level per row, because a spreadsheet outlines the hierarchy itself and a file at
 * the screen's fold would have thrown away the rows a reader cannot get back. `visible()` is the
 * one reading of "which rows are on show", and `[]` means folded to the roots.
 */
final class LedgerTree
{
    /**
     * The tree over a statement's leaf rows: those rows plus every ancestor, each ancestor carrying
     * the column sums of the leaves beneath it.
     *
     * @param  iterable<int, array<string, mixed>>  $leafRows  each with `account_id`, `code`, `name_en`, `name_ar`, `type` and the `$sumKeys`
     * @param  list<string>  $sumKeys  the money columns to roll up
     * @return list<array<string, mixed>> depth-first; each node adds `depth`, `parent_code`, `is_leaf` (a statement row — the ledger link) and `has_children` (the fold control); a postable parent on an imported chart is both
     */
    public static function build(iterable $leafRows, array $sumKeys): array
    {
        $chart = StatementGroups::chart();
        $nodes = [];

        foreach ($leafRows as $row) {
            $id = (int) $row['account_id'];

            // A statement row is a leaf. On an IMPORTED chart a postable account can also be a
            // parent (EG-28 places no rule against it), so a row whose node an earlier leaf already
            // created as an ancestor keeps that node's roll-up and adds its own figures to it —
            // overwriting would drop the children summed so far.
            if (isset($nodes[$id])) {
                foreach ($sumKeys as $key) {
                    $nodes[$id][$key] = round((float) $nodes[$id][$key] + (float) ($row[$key] ?? 0), 2);
                }

                $nodes[$id]['is_leaf'] = true;
            } else {
                $nodes[$id] = ['is_leaf' => true, 'has_children' => false] + $row;
            }

            // Walk up, summing into every ancestor. A parent is derived from a strict code prefix
            // (`LedgerAccount::saving`), so the app cannot write a cycle; a hand-edited row could,
            // and the walk stops at the first id it has already visited so nothing is summed twice
            // — the same guard `StatementGroups::topBelowRoot()` carries, keyed on identity rather
            // than a count.
            $pid = $chart[$id]['parent_id'] ?? null;
            $visited = [$id => true];

            while ($pid !== null && isset($chart[$pid]) && ! isset($visited[$pid])) {
                $visited[$pid] = true;
                $nodes[$pid] ??= [
                    'account_id' => $pid,
                    'code' => $chart[$pid]['code'],
                    'name_en' => $chart[$pid]['name_en'],
                    'name_ar' => $chart[$pid]['name_ar'],
                    'type' => $chart[$pid]['type'],
                    'is_leaf' => false,
                    ...array_fill_keys($sumKeys, 0.0),
                ];
                $nodes[$pid]['has_children'] = true;

                foreach ($sumKeys as $key) {
                    $nodes[$pid][$key] = round((float) $nodes[$pid][$key] + (float) ($row[$key] ?? 0), 2);
                }

                $pid = $chart[$pid]['parent_id'] ?? null;
            }
        }

        // Children by parent, roots being every node whose parent is not itself on the tree — or
        // whose ancestry never reaches the top (a cycle): such a node is placed at the root rather
        // than dropped, so the tree still carries every leaf the totals count.
        $children = [];
        $roots = [];

        foreach ($nodes as $id => $node) {
            $pid = $chart[$id]['parent_id'] ?? null;

            if ($pid !== null && isset($nodes[$pid]) && self::reachesTheTop($pid, $chart)) {
                $children[$pid][] = $id;
            } else {
                $roots[] = $id;
            }
        }

        $byCode = fn (int $a, int $b): int => strcmp((string) $nodes[$a]['code'], (string) $nodes[$b]['code']);
        usort($roots, $byCode);

        $ordered = [];
        $walk = function (int $id, int $depth, ?string $parentCode) use (&$walk, &$ordered, $nodes, $children, $byCode): void {
            $ordered[] = $nodes[$id] + ['depth' => $depth, 'parent_code' => $parentCode];

            $kids = $children[$id] ?? [];
            usort($kids, $byCode);

            foreach ($kids as $kid) {
                $walk($kid, $depth + 1, (string) $nodes[$id]['code']);
            }
        };

        foreach ($roots as $root) {
            $walk($root, 0, null);
        }

        return $ordered;
    }

    /**
     * Whether an id's parent chain ends at a top-level account rather than looping.
     *
     * @param  array<int, array{parent_id: ?int}>  $chart
     */
    private static function reachesTheTop(int $id, array $chart): bool
    {
        $visited = [];

        while ($id !== null && isset($chart[$id])) {
            if (isset($visited[$id])) {
                return false;
            }

            $visited[$id] = true;
            $id = $chart[$id]['parent_id'];
        }

        return true;
    }

    /**
     * The nodes on show once the given codes are UNFOLDED. **A tree opens folded to its roots**
     * (the operator's ask, 2026-09-12: *"the tree by default is minimized"*): a node is shown when
     * every ancestor of it is in `$expandedCodes`, so `[]` is the roots alone — one line per
     * account type that moved — and `parentCodes()` is the whole tree. A folded node stays, everything beneath it goes — at any depth, because
     * the parent of a hidden node is itself hidden.
     *
     * @param  list<array<string, mixed>>  $nodes  as {@see build()} returns them
     * @param  list<string>  $expandedCodes
     * @return list<array<string, mixed>> each node adds `collapsed` — true on a parent whose children are hidden
     */
    public static function visible(array $nodes, array $expandedCodes = []): array
    {
        $open = array_fill_keys(array_map('strval', $expandedCodes), true);
        $hidden = [];
        $shown = [];

        foreach ($nodes as $node) {
            $parent = $node['parent_code'];
            $isHidden = $parent !== null && (isset($hidden[$parent]) || ! isset($open[$parent]));

            if ($isHidden) {
                $hidden[(string) $node['code']] = true;

                continue;
            }

            $shown[] = $node + ['collapsed' => $node['has_children'] && ! isset($open[(string) $node['code']])];
        }

        return $shown;
    }

    /**
     * Every code that has something beneath it — what "unfold all" opens, and what a caller with
     * no screen (a scheduled export) passes to print the whole tree.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<string>
     */
    public static function parentCodes(array $nodes): array
    {
        return array_values(array_map(
            fn (array $n): string => (string) $n['code'],
            array_filter($nodes, fn (array $n): bool => (bool) $n['has_children']),
        ));
    }
}
