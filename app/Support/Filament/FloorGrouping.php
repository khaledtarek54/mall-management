<?php

namespace App\Support\Filament;

use Filament\Tables\Grouping\Group;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Group by floor, in the order the building has them" — for any table whose model points at
 * `floors`.
 *
 * Written once for `OccupancyMap` and shared when the rentable-item map arrived (2026-08-26),
 * because the only thing that differed between the two was the table name inside the subquery, and
 * a second copy of a raw-SQL ordering expression is how they come to sort a basement differently.
 *
 * ## Why the order is a subquery and not a join
 *
 * Ordered by the property's floor REGISTER. This replaced a three-clause `orderByRaw` (a CASE for
 * 'ground', then `length()`, then the value) that got the common case right — Ground → 1 → 2 → 10
 * — and then sorted a BASEMENT after the tenth floor, because the CASE only knew about the ground
 * floor. It was raw SQL on `lower()`/`length()` (the cross-database hazard this project has hit
 * twice) and it lived in one page, so every other consumer of the free-text column still got plain
 * string order.
 *
 * A correlated subquery, **not a join**: a map page scopes its own query on an unqualified
 * `asset_id`, and joining `floors` makes that ambiguous — that table has one too. This leaves the
 * base query's shape untouched.
 *
 * It is raw SQL again, but not the kind that was removed: the old expression encoded floor NAMING
 * in SQL (a CASE listing 'ground', 'g', '0') and had to grow for every label an operator invented.
 * This encodes only "order by the floor's level, unfloored last", and `coalesce` + a scalar
 * subquery behave the same on MySQL and SQLite.
 *
 * Unfloored records sort LAST — a record with no floor is not the ground floor.
 */
class FloorGrouping
{
    /** The sentinel that puts an unfloored record after the top floor rather than in the basement. */
    public const UNFLOORED = 9999;

    /**
     * @param  string  $table  the grouped model's own table — it must carry a `floor_id`
     */
    public static function make(string $table): Group
    {
        return Group::make('floor.code')
            ->label(__('admin.pdf.floor'))
            ->titlePrefixedWithLabel()
            ->orderQueryUsing(fn (Builder $query) => $query->orderByRaw(
                'coalesce((select level from floors where floors.id = '.$table.'.floor_id), '.self::UNFLOORED.')'
            ));
    }
}
