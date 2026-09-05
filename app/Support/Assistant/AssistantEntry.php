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
     * Whether the signed-in reader could actually open what this entry points at.
     *
     * Lives on the ENTRY because two surfaces ask it — the assistant, which will not suggest a
     * screen the reader cannot open, and global search, which will not list one. A second copy is
     * a second thing to keep in step, and the one that drifted would be the one that leaked.
     *
     * A TASK is asked of the RESOURCE, not of the create page. Measured when the assistant shipped:
     * a `viewer` — who may create nothing anywhere — was offered "New invoice" with a link straight
     * to the form, because the page's own `canAccess()` answered true and the refusal only arrived
     * after the click. `canCreate()` is the right question and the one the button itself asks.
     *
     * Asked HERE rather than while building the corpus, deliberately: the corpus is memoised per
     * locale and shared by every request, so filtering it by the current user would hand the next
     * reader whatever the previous one was allowed to see.
     *
     * `rescue()`d to FALSE: a `canAccess()` that throws is a screen whose access cannot be
     * established, and the safe reading of that is "no". Failing open would be a permission bypass
     * reached through a search box.
     */
    public function isReachableByReader(): bool
    {
        return rescue(
            function (): bool {
                if ($this->kind === 'task') {
                    return (bool) $this->key::canCreate();
                }

                return (bool) $this->screen::canAccess();
            },
            false,
            report: false,
        );
    }

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
