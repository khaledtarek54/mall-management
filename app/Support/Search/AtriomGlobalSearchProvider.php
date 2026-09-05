<?php

namespace App\Support\Search;

use App\Support\Assistant\AssistantCorpus;
use App\Support\SearchPolicy;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The global search provider for both panels.
 *
 * Filament's stock provider is a loop over every resource with no floor on the
 * query and no ordering beyond a per-resource `$globalSearchSort` integer that
 * nobody had set. This replaces it with three behaviours, all of which have to
 * live at the provider level because they are about the result set AS A WHOLE
 * rather than about any one resource.
 *
 * Authorization and property isolation are NOT re-implemented here, deliberately.
 * `canGloballySearch()` calls `canAccess()`, and `getGlobalSearchResults()` runs
 * through `getGlobalSearchEloquentQuery()` → `getEloquentQuery()`, which is where
 * every resource in this codebase applies its property scope (`ScopesViaProperty`
 * or a hand-rolled `asset_id` clause). Both gates are inherited by construction;
 * a second copy here would be a second thing to keep in sync, and the one that
 * drifted would be the one nobody tested.
 */
class AtriomGlobalSearchProvider implements GlobalSearchProvider
{
    /**
     * How many screens the palette will offer.
     *
     * Small on purpose: this category sits under every record category, and a long tail of
     * loosely-matching screens pushes the records the operator was probably looking for off the
     * bottom of the dropdown.
     */
    public const MAX_SCREEN_RESULTS = 5;

    public function getResults(string $query): ?GlobalSearchResults
    {
        // ---- 1. A floor on the query ------------------------------------
        //
        // Every keystroke fans out one query per resource — roughly 35 of them,
        // each a full table scan, since a `LIKE '%term%'` cannot use an index.
        // A single character matches most of the database, so the expensive
        // answer is also the useless one. Measured on the fold, not the raw
        // string: "؟؟" is two characters that mean nothing once folded.
        if (count(SearchText::words($query)) === 0
            || mb_strlen(SearchText::normalize($query)) < SearchPolicy::MIN_QUERY_LENGTH) {
            return GlobalSearchResults::make();
        }

        $resources = array_filter(
            Filament::getResources(),
            fn (string $resource): bool => $resource::canGloballySearch(),
        );

        // ---- 2. Deterministic category order ----------------------------
        //
        // From ONE ordered list in SearchPolicy rather than a magic integer
        // scattered across 35 classes. Ties (anything unlisted) keep a stable
        // order via the resource name, so the dropdown does not reshuffle
        // between identical searches.
        usort($resources, fn (string $a, string $b): int => [SearchPolicy::rank($a), $a] <=> [SearchPolicy::rank($b), $b]);

        $exactMatches = [];
        $others = [];

        foreach ($resources as $resource) {
            $results = $resource::getGlobalSearchResults($query);

            if (! $results->count()) {
                continue;
            }

            $entry = [$resource::getPluralModelLabel(), $results];

            // ---- 3. An exact hit outranks the standing order -------------
            //
            // Pasting `INV-AW-202607-0110` should surface that invoice first,
            // not whatever category happens to rank highest — and an operator
            // reading a number off a printed document is the single most common
            // way this search bar gets used. Detected by comparing folds, so it
            // works for a number typed without its dashes and for an Arabic name
            // spelled the other way.
            //
            // Self-maintaining on purpose: the alternative was a prefix→resource
            // map (INV- → invoices, WO- → work orders), which is one more
            // registry to drift out of step with the document-number formats.
            if ($this->containsExactMatch($results, $query)) {
                $exactMatches[] = $entry;
            } else {
                $others[] = $entry;
            }
        }

        $builder = GlobalSearchResults::make();

        foreach ([...$exactMatches, ...$others] as [$label, $results]) {
            $builder->category($label, $results);
        }

        // ---- 4. The SCREENS, last ---------------------------------------
        //
        // ⌘K reached records only (UX5-04), so the 33 report and utility PAGES — the VAT return,
        // month-end close, the rent roll, the ageing report — were reachable by scanning a
        // fourteen-group sidebar, while the palette that UX-28 advertises to every operator could
        // not find them. A search box that answers for half the panel is worse than one that
        // answers for none, because the half it misses reads as absent rather than as elsewhere.
        //
        // Deliberately LAST, under every record category: someone typing into this box is usually
        // holding a document number, and a screen suggestion above the invoice they pasted would
        // be a worse answer to the commoner question.
        foreach ($this->screenResults($query) as $label => $results) {
            $builder->category($label, $results);
        }


        return $builder;
    }

    /**
     * Screens, reports and create-forms matching the query — the assistant's corpus, re-read.
     *
     * NOT a second index. `AssistantCorpus` already scores every screen and report against a
     * folded query in both languages, carries the operator's own vocabulary (`synonyms`), and is
     * memoised per locale; ranking is most of that feature and a second copy here would be a
     * second thing to keep good. What differs is only the presentation and the ceiling.
     *
     * Access is asked per ENTRY and per REQUEST through `isReachableByReader()` — never while
     * building the corpus, which is shared between operators.
     *
     * @return array<string, array<int, GlobalSearchResult>>
     */
    protected function screenResults(string $query): array
    {
        $words = AssistantCorpus::tokenise($query);

        if ($words === []) {
            return [];
        }

        $scored = [];

        foreach (AssistantCorpus::entries(app()->getLocale()) as $entry) {
            ['score' => $score, 'hits' => $hits] = $entry->scoreAgainst($words);

            // Every word the operator typed must land somewhere. A single shared word ("tenant",
            // "report") matches most of the corpus, and a palette that answers everything with
            // twelve screens is one people stop opening.
            if ($score <= 0 || $hits < count($words)) {
                continue;
            }

            if (! $entry->isReachableByReader()) {
                continue;
            }

            $url = rescue(fn (): ?string => $entry->screen::getUrl(), null, report: false);

            if ($url === null) {
                continue;
            }

            $scored[] = ['score' => $score, 'title' => $entry->title, 'url' => $url, 'kind' => $entry->kind];
        }

        if ($scored === []) {
            return [];
        }

        usort($scored, fn (array $a, array $b): int => [$b['score'], $a['title']] <=> [$a['score'], $b['title']]);

        $results = [];

        foreach (array_slice($scored, 0, self::MAX_SCREEN_RESULTS) as $row) {
            $results[] = new GlobalSearchResult(title: $row['title'], url: $row['url']);
        }

        return [(string) __('admin.search.screens') => $results];
    }

    /**
     * Does any result's title fold to exactly the folded query?
     *
     * @param  iterable<GlobalSearchResult>  $results
     */
    protected function containsExactMatch(iterable $results, string $query): bool
    {
        $needle = implode(' ', SearchText::words($query));

        if ($needle === '') {
            return false;
        }

        foreach ($results as $result) {
            $title = $result->title;

            if ($title instanceof Htmlable) {
                $title = $title->toHtml();
            }

            if (SearchText::normalize(strip_tags($title)) === $needle) {
                return true;
            }
        }

        return false;
    }
}
