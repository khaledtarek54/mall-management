<?php

namespace App\Filament\Admin\RelationManagers\Concerns;

use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;

/**
 * Put the row count on a relation-manager TAB, so thirteen tabs stop being thirteen guesses.
 *
 * ## The problem
 *
 * A lease record carries **thirteen** relation managers, a tenant nine, a property six. Filament
 * renders them as a row of tabs and none of them said anything about its contents, so finding out
 * whether a lease had any options, any clauses, any percentage-rent tiers or any straight-line
 * adjustments meant clicking each one — and clicking a tab that turns out to be empty is the single
 * most repeated wasted action on a record page. Not one of the forty-nine relation managers in this
 * panel declared a badge.
 *
 * ## Zero shows NOTHING, on purpose
 *
 * `getBadge()` returns null for an empty relation rather than "0". A row of thirteen grey zeroes
 * carries the same information as no badges at all while costing far more attention; what an
 * operator wants to see is the three tabs that have something in them. It is the same rule the
 * navigation badges follow — `$count > 0 ? (string) $count : null`.
 *
 * ## Deferred, so a count never delays the page
 *
 * `$isBadgeDeferred = true` makes Filament pass the badge as a closure and resolve it after the
 * record page has rendered ({@see RelationManager::
 * getTabComponent()}). Thirteen COUNTs that block first paint would be a worse screen than no
 * counts; thirteen that arrive a moment later are free. This matters here specifically because the
 * panel had already been measured issuing fifty redundant navigation-badge COUNTs per page — the
 * lesson being that a count is cheap exactly once.
 *
 * ## What it counts, and when NOT to use it
 *
 * The plain relationship the manager declares. **A relation manager that filters its own table must
 * not use this trait unmodified** — a tab that shows two rows under a badge saying five is worse
 * than an unbadged tab, because the operator now has a number they cannot reconcile and no way to
 * tell which is wrong. Such a manager either overrides {@see badgeCount()} to apply the same
 * narrowing, or leaves the trait off. `RelationManagerTabBadgesMatchTheirRowsTest` drives the real
 * record page and compares each badge against the rows that manager's table actually returns, so a
 * mismatch is a failing build rather than a number nobody checks.
 */
trait CountsItsRows
{
    /**
     * Resolved after the page renders, not during it.
     *
     * Overridden as a METHOD, never as the `$isBadgeDeferred` property. A trait that redeclares a
     * property its host class already declares with a different default is a PHP **fatal** —
     * "the definition differs and is considered incompatible" — and it fires at class load, which
     * in a Pest run means the whole suite exits with no output on either stream. That is the
     * symptom this codebase has chased three times for a different cause; here the tell was that a
     * path-scoped run of the helper-uniqueness gate produced nothing either.
     *
     * Stated on the trait rather than left to each user of it: the whole reason a thirteen-tab
     * record can afford badges is that they do not block the paint, and a manager that forgot the
     * flag would silently reintroduce the cost for everyone on that page.
     */
    public static function isBadgeDeferred(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = static::badgeCount($ownerRecord);

        return $count > 0 ? (string) $count : null;
    }

    /**
     * How many rows this tab holds.
     *
     * Override where the manager narrows its own table, so the badge counts what the tab shows.
     */
    protected static function badgeCount(Model $ownerRecord): int
    {
        $relationship = static::getRelationshipName();

        if (! method_exists($ownerRecord, $relationship)) {
            return 0;
        }

        return $ownerRecord->{$relationship}()->count();
    }
}
