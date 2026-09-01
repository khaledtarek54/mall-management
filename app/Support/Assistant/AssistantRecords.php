<?php

namespace App\Support\Assistant;

use App\Support\Search\AtriomGlobalSearchProvider;
use Filament\GlobalSearch\GlobalSearchResult;

/**
 * The records a question named — "how much does Zara owe", "INV-AW-202608-0417".
 *
 * ## It reuses the global search rather than querying anything
 *
 * `AtriomGlobalSearchProvider` already answers "which records match this text", already runs each
 * resource's `canGloballySearch()` (which calls `canAccess()`), and already scopes through
 * `getEloquentQuery()` — which is where every resource applies its property isolation. Calling it
 * inherits both gates by construction. A second search here would be a second thing to keep in
 * step, and the one that drifted would be the one nobody tested.
 *
 * ## Which words to search for, and why not the whole sentence
 *
 * The global search ANDs its words, so handing it "how much does Zara owe" matches nothing — no
 * record contains all of those. The words worth searching are the ones the DOCUMENTATION does not
 * know: every word in a guide, a screen title or a report keyword is vocabulary of the system, and
 * a word appearing in none of it is far more likely to be a tenant's name, a unit code or a
 * document number.
 *
 * That heuristic costs nothing to maintain — it is derived from the corpus, which is itself derived
 * from two registries — and it degrades safely in both directions. A proper noun that happens to
 * appear in some guide is simply not searched for (the screens still answer). A domain word that
 * appears in no guide triggers one search that finds nothing, which the provider's own query floor
 * makes cheap.
 */
final class AssistantRecords
{
    public const MAX_RECORDS = 3;

    /**
     * The words this query uses that the documentation has never heard of.
     *
     * @param  array<int, string>  $words  already folded and stripped of stop words
     * @param  array<int, AssistantEntry>  $corpus
     * @return array<int, string>
     */
    public static function unknownWords(array $words, array $corpus): array
    {
        $known = [];

        foreach ($corpus as $entry) {
            $known += $entry->terms;
        }

        return array_values(array_filter(
            $words,
            fn (string $word): bool => ! isset($known[$word]),
        ));
    }

    /**
     * Records matching those words, as assistant results.
     *
     * `rescue()`d to an empty list: the provider reaches for the current Filament panel, which a
     * console replay of the miss list does not have. A question that finds no records is a normal
     * outcome, so failing quiet here loses nothing — and taking the whole answer down because the
     * record half could not run would be the wrong trade.
     *
     * @param  array<int, string>  $words
     * @return array<int, array{kind: string, key: string, screen: string, title: string, score: int, url: string|null}>
     */
    public static function find(array $words): array
    {
        if ($words === []) {
            return [];
        }

        return rescue(function () use ($words): array {
            $results = app(AtriomGlobalSearchProvider::class)->getResults(implode(' ', $words));

            if ($results === null) {
                return [];
            }

            $found = [];

            foreach ($results->getCategories() as $category => $items) {
                foreach ($items as $item) {
                    if (! $item instanceof GlobalSearchResult) {
                        continue;
                    }

                    $found[] = [
                        'kind' => 'record',
                        // The category is what the reader needs to tell two "Zara"s apart — a
                        // tenant and a lease can carry the same name — so it travels as the key
                        // and is what the miss list groups on.
                        'key' => (string) $category,
                        'screen' => (string) $category,
                        'title' => strip_tags((string) $item->title),
                        // A record is a direct hit or it is nothing; there is no partial credit to
                        // express, and inventing a score would make it sortable against screens
                        // scored on a completely different basis.
                        'score' => 0,
                        'url' => $item->url,
                    ];

                    if (count($found) >= self::MAX_RECORDS) {
                        return $found;
                    }
                }
            }

            return $found;
        }, [], report: false);
    }
}
