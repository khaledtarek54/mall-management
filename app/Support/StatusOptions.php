<?php

namespace App\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * What a STATUS FILTER may offer — derived from the column, never hand-kept.
 *
 * ## The bug this exists to make impossible
 *
 * A `SelectFilter` over a status column was a list written out by whoever was thinking about the
 * statuses that mattered that afternoon. The column then grows one — `voided` arrived on `payments`
 * on 2026-08-28, `written_off` on `invoices` before it — and the filter silently stops being able
 * to find rows the list beside it is already rendering, coloured and labelled.
 *
 * Measured at HEAD on 2026-09-04, on the two portal filters that were replaced (SW-029):
 *
 *   - the portal invoice filter offered **4 of the 8** statuses a tenant may be shown. `disputed`,
 *     `cancelled`, `credited` and `written_off` were unreachable — and every one of them has an arm
 *     in the `status` column's own `formatStateUsing()` a few lines above the filter, so the tenant
 *     can read the word and cannot filter by it.
 *   - the portal payment filter offered **5 of the 9** the column accepts. `initiated`,
 *     `authorized`, `bounced` and `voided` were unreachable, and a card payment a tenant began and
 *     never finished is exactly an `initiated` row on their own list.
 *
 * ## Why one seam and not a fix per table
 *
 * The reasoning was already written out — correctly, with a five-line comment — on ONE of the
 * portal's three status filters (credit notes, which derived from {@see TenantVisibility}). Its two
 * neighbours never got it. That is the shape this codebase keeps paying for: a rule stated in one
 * file and copied into none.
 *
 * `ValueSets` already answers *what may this column hold* and `TenantVisibility` already answers
 * *what may a tenant be shown*. Neither had a LABELLED reader, so each filter grew its own — which
 * is also why `admin.statuses.*` was being read three different ways for the same job.
 *
 * A status added to `ValueSets` is therefore filterable **by existing**, the same safe direction
 * `TenantVisibility` chose for the scope itself: the reader loses nothing by default, and anything
 * withheld has to be withheld deliberately.
 */
final class StatusOptions
{
    /**
     * Every status the column may hold, labelled, in the value set's own (lifecycle) order.
     *
     * The OPERATOR's half: an admin list shows every row the column can carry, so its filter has to
     * be able to find every one of them.
     */
    public static function for(string $table, string $column = 'status', ?string $group = null): array
    {
        $values = ValueSets::allowed($table, $column);

        if ($values === null) {
            // A developer error, deliberately loud, and deliberately an InvalidArgumentException —
            // it renders as a 500 that nobody is meant to read in any language. The alternative is
            // an EMPTY dropdown, and an empty picker reads as "there are none of those" rather than
            // as a broken filter; this codebase has been bitten by that reading three times. It
            // fires on the first render of the list page, which the panel sweeps already do.
            throw new InvalidArgumentException(
                "{$table}.{$column} has no registered value set, so a filter over it can offer nothing. "
                .'Register the column in App\Support\ValueSets::SETS.'
            );
        }

        // `invoices` -> `admin.statuses.invoice`: the convention ActivityVocabulary already resolves
        // status vocabulary by. Pass $group for a column that does not follow it.
        $group ??= Str::singular($table);

        $options = [];

        foreach ($values as $value) {
            // orHumanized(), never `__($key, [], $value)`: the third argument to `__()` is the
            // LOCALE, and a fallback passed there renders the ENGLISH string on the Arabic panel.
            $options[$value] = Translate::orHumanized("admin.statuses.{$group}.{$value}", $value);
        }

        return $options;
    }

    /**
     * The subset a TENANT may be shown, labelled — what a PORTAL status filter offers.
     *
     * Composed from {@see self::for()} rather than derived a second time, so the two can never
     * disagree about what a status IS or what it is called; the narrowing is `TenantVisibility`'s
     * alone. `array_intersect_key` keeps `for()`'s order.
     *
     * The `?? []` is only reachable for a column with no registered value set, and `for()` has
     * already thrown for that — it is here so this method holds no second opinion about it.
     */
    public static function forTenant(string $table, string $column = 'status', ?string $group = null): array
    {
        return array_intersect_key(
            self::for($table, $column, $group),
            array_flip(TenantVisibility::visibleFor($table, $column) ?? []),
        );
    }
}
