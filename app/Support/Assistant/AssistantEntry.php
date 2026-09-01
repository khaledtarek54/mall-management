<?php

namespace App\Support\Assistant;

/**
 * One thing the assistant can point at: a screen, or a report.
 *
 * `$terms` is the folded word => weight map this entry is matched on. It is built once per locale
 * by {@see AssistantCorpus} and never at query time, because the corpus is the same for every
 * operator and only the ACCESS filter is per-person.
 */
final class AssistantEntry
{
    /**
     * @param  'screen'|'report'  $kind
     * @param  class-string  $screen  the Filament page or resource this points at
     * @param  array<string, int>  $terms  folded word => weight
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $key,
        public readonly string $screen,
        public readonly string $title,
        public readonly array $terms,
    ) {}

    /**
     * What this entry scores against one folded query word, and how many distinct words it hit.
     *
     * Returned together rather than as two passes because ranking needs both: a long guide can
     * out-score a precise title match on raw weight alone, and "matched three of the four words
     * you typed" is the better tie-break.
     *
     * @param  array<int, string>  $words
     * @return array{score: int, hits: int}
     */
    public function scoreAgainst(array $words): array
    {
        $score = 0;
        $hits = 0;

        foreach ($words as $word) {
            $weight = $this->terms[$word] ?? 0;

            if ($weight > 0) {
                $score += $weight;
                $hits++;
            }
        }

        return ['score' => $score, 'hits' => $hits];
    }
}
