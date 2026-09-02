<?php

namespace App\Support\Assistant;

use App\Models\AssistantDocChunk;
use App\Support\Search\SearchText;

/**
 * The documentation tier — consulted only when the screen guides could not answer.
 *
 * ## Why it is a FALLBACK and not a peer
 *
 * A screen guide answers "how do I do X" with the screen that does X and a link to it. A paragraph
 * of prose answers it with words. When both exist the guide is strictly better, so ranking them
 * together would let a well-written chapter push the actual screen off the top of the page. This
 * runs only when the guides and the report catalogue produced nothing at all — which is precisely
 * the case A0 recorded as a miss, and precisely what this tier was built to reduce.
 *
 * ## Every word, not the best few
 *
 * See {@see AssistantDocChunk::scopeMatchingAll()}. Hundreds of pages of prose contain almost every
 * common word somewhere, so partial-overlap scoring would answer every question with the longest
 * chapter. Requiring all of them is what makes a hit mean anything.
 */
final class AssistantDocs
{
    public const MAX_RESULTS = 3;

    /** A heading hit is worth what a screen title is worth; the body is worth what a guide body is. */
    public const WEIGHT_HEADING = AssistantCorpus::WEIGHT_TITLE;

    /** How much a single word repeating can contribute, so length alone cannot win. */
    public const MAX_FREQUENCY_PER_WORD = 3;

    /** What a chunk gives up for having matched only all-but-one of the words. */
    public const RELAXED_PENALTY = 4;

    /** Candidates read per pass. The corpus is a few hundred sections; this is a guard, not a page. */
    public const CANDIDATE_LIMIT = 25;

    /**
     * Chunks containing every word — the reader's language first, then any.
     *
     * The handbook is bilingual but the training walkthroughs are English only, so an Arabic
     * question that finds nothing Arabic is better answered in English than not at all.
     *
     * @param  array<int, string>  $words
     * @return \Illuminate\Support\Collection<int, AssistantDocChunk>
     */
    private static function matching(array $words, string $locale): \Illuminate\Support\Collection
    {
        $own = AssistantDocChunk::query()->matchingAll($words)
            ->where('locale', $locale)->limit(self::CANDIDATE_LIMIT)->get();

        return $own->isNotEmpty()
            ? $own
            : AssistantDocChunk::query()->matchingAll($words)->limit(self::CANDIDATE_LIMIT)->get();
    }

    /**
     * Chunks containing every word but one, tried for each word in turn.
     *
     * @param  array<int, string>  $words
     * @return \Illuminate\Support\Collection<int, AssistantDocChunk>
     */
    private static function matchingAllButOne(array $words, string $locale): \Illuminate\Support\Collection
    {
        $found = collect();

        foreach (array_keys($words) as $omit) {
            $subset = array_values(array_diff_key($words, [$omit => null]));

            $found = $found->concat(self::matching($subset, $locale)->all());
        }

        return $found->unique('id')->take(self::CANDIDATE_LIMIT);
    }

    /**
     * @param  array<int, string>  $words
     * @return array<int, array{kind: string, key: string, screen: string, title: string, score: int, url: string|null, excerpt: string}>
     */
    public static function find(array $words, string $locale): array
    {
        if ($words === []) {
            return [];
        }

        return rescue(function () use ($words, $locale): array {
            // The reader's own language first. Only if it has nothing to say are the other
            // languages read — the handbook is bilingual but the training walkthroughs are English
            // only, so an Arabic question that finds nothing Arabic is better answered in English
            // than not at all.
            $chunks = self::matching($words, $locale);

            // ONE RELAXATION, and only when the strict pass found nothing.
            //
            // There is no stemming here, so "what happens when a cheque BOUNCES" matches nothing
            // while the walkthrough that answers it says "bounced". Requiring every word but one
            // recovers that case without opening the floodgates: two words still have to agree,
            // and the omitted word costs the chunk a penalty so a relaxed hit can never outrank a
            // strict one. Only worth trying when there is more than one word to drop.
            $relaxed = false;

            if ($chunks->isEmpty() && count($words) > 2) {
                $relaxed = true;
                $chunks = self::matchingAllButOne($words, $locale);
            }

            $scored = [];

            foreach ($chunks as $chunk) {
                $headingWords = AssistantCorpus::tokenise($chunk->heading);
                $hits = count(array_intersect($words, $headingWords));

                // A heading hit is the strongest signal, but most chunks have none — and with
                // every candidate then tied at 1, the order fell to whichever heading sorted
                // first alphabetically, which is no order at all. Frequency breaks that tie the
                // way a reader would: a section that DWELLS on the words was written about them,
                // where one that mentions each once is about something else. Capped per word so a
                // long chapter cannot win on length alone.
                $frequency = 0;

                foreach ($words as $word) {
                    $frequency += min(substr_count($chunk->search_blob, $word), self::MAX_FREQUENCY_PER_WORD);
                }

                $scored[] = [
                    'chunk' => $chunk,
                    'score' => max(1, ($hits * self::WEIGHT_HEADING) + $frequency - ($relaxed ? self::RELAXED_PENALTY : 0)),
                ];
            }

            usort($scored, fn (array $a, array $b): int => [$b['score'], $a['chunk']->heading] <=> [$a['score'], $b['chunk']->heading]);

            return array_map(fn (array $row): array => [
                'kind' => 'doc',
                'key' => $row['chunk']->path,
                'screen' => $row['chunk']->path,
                'title' => $row['chunk']->heading,
                'score' => $row['score'],
                'url' => $row['chunk']->url,
                'excerpt' => $row['chunk']->excerpt,
            ], array_slice($scored, 0, self::MAX_RESULTS));
        }, [], report: false);
    }
}
