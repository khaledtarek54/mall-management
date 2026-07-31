<?php

namespace App\Filament\Concerns;

use App\Support\Search\SearchText;
use App\Support\SearchPolicy;
use Illuminate\Database\Eloquent\Builder;

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
