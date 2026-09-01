<?php

declare(strict_types=1);

namespace App\Support\Filament;

use App\Support\Search\SearchText;
use Illuminate\Support\Collection;

/**
 * Search over a report table whose rows are an ARRAY, not a query.
 *
 * ## Why this exists
 *
 * Six report pages build their rows in PHP and hand Filament a Collection through `->records()`.
 * Filament cannot search those on its own — it *offers* the search state to the closure
 * (`HasRecords::getTableRecords()` injects `search`, `sort`, `page`…), but a closure that ignores
 * it gets no filtering, so all six were correctly written `->searchable(false)`.
 *
 * The result on the rent roll — the single most-used commercial report there is — was that finding
 * one shop meant paging. Reported on 2026-09-01 as "I put the date in and it still doesn't show":
 * a 34-row roll, default page size 25, sorted by unit code, and the unit being hunted for was row
 * **34 of 34**. It was on page two the whole time, and there was no box to type it into. On a mall
 * with two hundred shops that is not a nuisance, it is the report not answering the question it
 * exists to answer.
 *
 * ## Folded on BOTH sides, like everything else here
 *
 * The stored value and the operator's query both go through {@see SearchText}, which is the rule
 * the whole panel's search follows and the reason «شركة» finds «شركه». Folding one side matches
 * nothing; folding neither means these tables would silently be the one surface in the app where
 * an Arabic spelling does not match — exactly the trap a raw-column search key is banned for.
 *
 * Words AND, fields OR: "zara c-16" narrows to a row matching both, matching Filament's own global
 * search semantics so the top bar and a report table cannot behave differently.
 *
 * A query that folds to nothing (pure punctuation) means **do not search**, never "match all" —
 * `SearchText::words()` states that contract and returning everything would be the friendlier and
 * completely wrong reading.
 */
class ReportSearch
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<string>  $keys  row keys to search across
     * @return Collection<int, array<string, mixed>>
     */
    public static function apply(Collection $rows, ?string $search, array $keys): Collection
    {
        $words = SearchText::words($search);

        if ($words === []) {
            return $rows;
        }

        return $rows->filter(function (array $row) use ($words, $keys): bool {
            $haystack = SearchText::blob(array_map(
                static fn (string $key): ?string => self::stringify($row[$key] ?? null),
                $keys,
            ));

            foreach ($words as $word) {
                if (! str_contains($haystack, $word)) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * Flatten one cell to something foldable.
     *
     * A report row is not a model: a cell may hold a Carbon (an expiry date somebody searches by
     * year), a float, or null. Anything that cannot become a string is skipped rather than
     * stringified into noise like "Array".
     */
    private static function stringify(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            $value instanceof \Stringable, is_object($value) && method_exists($value, '__toString') => (string) $value,
            default => null,
        };
    }
}
