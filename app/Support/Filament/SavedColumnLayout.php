<?php

namespace App\Support\Filament;

/**
 * The column layout a saved view remembers — captured from one page, re-applied to another's table.
 *
 * Shared by `SavesTableViews` (resource lists) and `SavesReportViews` (the 23 catalogued reports),
 * because they are the same feature over two different tables and a second copy of this logic is
 * how the two would drift into remembering different things.
 *
 * ## Only the toggles and the order
 *
 * Never the labels or hidden flags that sit beside them in Filament's own state: those are
 * re-derived from the VIEWER's table on every apply, so storing them would be a stale snapshot of
 * somebody else's screen — and it is what makes a shared view safe, since the layout is rebuilt
 * from what the reader may already see rather than from what the author could.
 *
 * A fixed column records no decision (Filament forces its toggle back on when it re-syncs), so
 * storing one would be noise that reads as a choice and would pin today's fixed set into a row read
 * a year from now.
 */
final class SavedColumnLayout
{
    /** The key the toggles are stored under. */
    public const TOGGLES = 'columns';

    /** The key the order is stored under — a separate question from which columns show. */
    public const ORDER = 'column_order';

    /**
     * What the operator is looking at, ready to store.
     *
     * @param  array<int, array<string, mixed>>  $tableColumns  Filament's own column-manager state
     * @return array{columns: array<string, bool>, column_order: array<int, string>}
     */
    public static function capture(array $tableColumns): array
    {
        $toggles = [];

        foreach ($tableColumns as $item) {
            // Column GROUPS are walked into rather than stored as containers: names are unique
            // across a table, so a flat map reapplies correctly whether or not the column still
            // sits in a group.
            foreach ($item['columns'] ?? [$item] as $column) {
                if (($column['isToggleable'] ?? false) && isset($column['name'])) {
                    $toggles[$column['name']] = (bool) ($column['isToggled'] ?? true);
                }
            }
        }

        return [
            self::TOGGLES => $toggles,
            // Top-level items only, groups included by their own name: a group keeps its children's
            // order within itself, and flattening here would make the stored list impossible to
            // reapply to a table whose grouping has since changed.
            self::ORDER => collect($tableColumns)
                ->pluck('name')
                ->filter(fn ($name): bool => is_string($name) && $name !== '')
                ->values()
                ->all(),
        ];
    }

    /**
     * The stored toggles, cast rather than trusted.
     *
     * This is JSON written by one version of the feature and read by another — the same reasoning
     * that makes a saved view's query parameters an allowlist.
     *
     * @param  array<string, mixed>|null  $state
     * @return array<string, bool>
     */
    public static function togglesFrom(?array $state): array
    {
        $columns = ($state ?? [])[self::TOGGLES] ?? null;

        if (! is_array($columns)) {
            return [];
        }

        $out = [];

        foreach ($columns as $name => $isToggled) {
            if (is_string($name) && $name !== '') {
                $out[$name] = (bool) $isToggled;
            }
        }

        return $out;
    }

    /**
     * The stored order. Empty means "the table's own order", which is what every row saved before
     * columns became reorderable says.
     *
     * @param  array<string, mixed>|null  $state
     * @return array<int, string>
     */
    public static function orderFrom(?array $state): array
    {
        $order = ($state ?? [])[self::ORDER] ?? null;

        if (! is_array($order)) {
            return [];
        }

        return array_values(array_filter($order, fn ($name): bool => is_string($name) && $name !== ''));
    }

    /**
     * Rebuild the reader's own column state with the saved toggles and order applied.
     *
     * Built from the DEFAULT state outward rather than from the stored row inward, which is what
     * makes a shared view safe: a name the current table does not have is never introduced, and a
     * column this reader may not toggle keeps whatever their table says.
     *
     * A name the order does not mention keeps its position at the END, in the table's own order —
     * so a column added to the resource after the view was saved appears rather than vanishing
     * because an old row failed to mention it.
     *
     * @param  array<int, array<string, mixed>>  $defaultState
     * @param  array<string, bool>  $toggles
     * @param  array<int, string>  $order
     * @return array<int, array<string, mixed>>
     */
    public static function rebuild(array $defaultState, array $toggles, array $order): array
    {
        $state = collect($defaultState)
            ->map(function (array $item) use ($toggles): array {
                if (isset($item['columns'])) {
                    $item['columns'] = collect($item['columns'])
                        ->map(fn (array $column): array => self::withToggle($column, $toggles))
                        ->all();

                    return $item;
                }

                return self::withToggle($item, $toggles);
            })
            ->all();

        if ($order === []) {
            return $state;
        }

        $position = array_flip($order);
        $last = count($order);

        usort($state, function (array $a, array $b) use ($position, $last): int {
            return ($position[$a['name'] ?? ''] ?? $last) <=> ($position[$b['name'] ?? ''] ?? $last);
        });

        return $state;
    }

    /**
     * One column, taking the saved toggle when the view stated one and this table allows it.
     *
     * The `isToggleable` check is OURS and is deliberately redundant: Filament's
     * `syncTableColumnStateItemAttributes()` forces a fixed column's toggle back on regardless,
     * which mutation testing confirmed is the layer actually enforcing it. Kept as a stated intent,
     * because an upstream implementation detail can change in a release and would silently remove
     * the protection.
     *
     * @param  array<string, mixed>  $column
     * @param  array<string, bool>  $toggles
     * @return array<string, mixed>
     */
    private static function withToggle(array $column, array $toggles): array
    {
        if (($column['isToggleable'] ?? false) && array_key_exists($column['name'] ?? '', $toggles)) {
            $column['isToggled'] = $toggles[$column['name']];
        }

        return $column;
    }
}
