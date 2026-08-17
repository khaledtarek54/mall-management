<?php

namespace App\Filament\Concerns;

use App\Support\Search\OptionDisplay;
use App\Support\Search\SearchText;
use App\Support\SearchPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Makes a Filament resource search the fold-normalized `search_text` blob rather
 * than raw columns — shared by both panels.
 *
 * A resource using this trait declares only its attributes:
 *
 *     public static function getGloballySearchableAttributes(): array
 *     {
 *         return ['search_text', 'tenant.search_text', 'lease.search_text'];
 *     }
 *
 * Every path must end in `search_text`, on this model or on a related one — a
 * relation path pointed at a raw column (`tenant.name`) would compare a FOLDED
 * query against an UNFOLDED value and match nothing for exactly the Arabic
 * spellings this exists to fix. `SearchPolicyConformanceTest` enforces the
 * suffix, because that failure is silent.
 *
 * ## Why this is a four-line override
 *
 * Filament already does the hard parts correctly: split the query on whitespace,
 * AND the words, OR across attributes, and turn a dotted path into a `whereHas`.
 * The ONLY thing wrong for this codebase is that it compares the operator's raw
 * keystrokes against the stored value. Since every attribute now points at a
 * column that is already folded, folding the QUERY the same way is the whole fix
 * — so this hands the folded words straight back to Filament rather than
 * reimplementing its constraint builder and inheriting the job of keeping a copy
 * of it correct across upgrades.
 */
trait SearchesNormalizedText
{
    /**
     * Rows per category, from the registry rather than a per-resource property.
     *
     * Filament's default is 50 PER RESOURCE and no resource had overridden it, so
     * one keystroke could hydrate ~1,750 models across every category to render
     * the handful that fit on screen. Setting it here means the number is one
     * constant in `SearchPolicy`, not 35 copies of `protected static int
     * $globalSearchResultsLimit` that drift apart the first time someone tunes
     * one of them.
     */
    public static function getGlobalSearchResultsLimit(): int
    {
        return SearchPolicy::RESULTS_PER_RESOURCE;
    }

    /**
     * What a search hit says about itself under its title — from the SAME registry the dropdowns
     * read (`App\Support\Search\OptionDisplay`).
     *
     * The details were per-resource before this and, predictably, present on some and missing on
     * others: 21 of ~35 searchable resources defined them, so an operator searching "Zara" got a
     * phone number and a status under a tenant and a bare title under a lease. Same registry, same
     * subtitle, everywhere — and adding a fact to a record's presenter now reaches the picker, the
     * chosen value AND the search bar in one edit.
     *
     * Returned as a LIST, not a map. Filament renders `label: value` for an associative array and
     * bare values for a list (`Arr::isAssoc`), and the subtitle is one composite line — inventing a
     * label for "A-114 · Atriom Walk · 0100 123 4567" would be worse than showing it plainly.
     *
     * A trait method loses to one declared on the class, which is the layering we want: a resource
     * that has already written its own details keeps them, and the other fourteen stop being blank.
     *
     * @return array<int, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        $option = OptionDisplay::for($record);

        return array_values(array_filter([
            $option->code,
            $option->subtitle,
            $option->badge,
        ]));
    }

    /**
     * Eager-load exactly what the details above reach for.
     *
     * Without this every search keystroke costs one query per result per relation — the N+1 that is
     * invisible on demo data and is the first thing an operator notices on a real portfolio, on the
     * one control they use most.
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->with(OptionDisplay::EAGER[static::getModel()] ?? []);
    }

    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        $words = SearchText::words($search);

        if ($words === []) {
            // The query folded away to nothing — it was punctuation, or a stray
            // diacritic. That must match NOTHING. Returning without touching the
            // builder would leave the query unconstrained and dump the first
            // page of every table into the results, which is the worst possible
            // answer to a keystroke the operator did not mean to make.
            $query->whereRaw('1 = 0');

            return;
        }

        // Re-joined with spaces: Filament splits on whitespace and ANDs the
        // parts, and folded words contain no punctuation, quotes or escapes, so
        // its `str_getcsv` split returns exactly the words we put in.
        parent::applyGlobalSearchAttributeConstraints($query, implode(' ', $words));
    }
}
