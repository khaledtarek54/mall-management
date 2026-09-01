<?php

namespace App\Services\Assistant;

use App\Http\Middleware\SetLocale;
use App\Models\AssistantQuestion;
use App\Support\Assistant\AssistantCorpus;
use App\Support\Assistant\AssistantEntry;
use App\Support\Search\SearchText;
use App\Support\TenantScope;
use Illuminate\Support\Facades\Auth;

/**
 * Answer one typed question by pointing at the screen or report that already answers it.
 *
 * There is no language model here and A0 does not need one. The system already carries a four-field
 * guide for every screen in two languages and a curated keyword list for every report; what it did
 * not carry was a way to ASK. This is that.
 *
 * ## Access is the gate, and it runs before ranking
 *
 * Every candidate is filtered through the screen's own `canAccess()`. That is not a nicety: it means
 * the assistant can never answer a question by naming a screen the reader cannot open — which would
 * be worse than no answer, because it reads as a broken permission rather than as a boundary. It
 * also means the same question gives an accountant and a technician different answers, correctly.
 *
 * ## Both languages, always — including a query typed in the OTHER one
 *
 * The corpus is per-locale, so an Arabic question is matched against Arabic guides. But an operator
 * working in Arabic routinely types the English term — "credit note", "CAM", "rent roll" — because
 * that is the word on the contract. Matching only the panel's locale answers those with nothing,
 * which reads as "the system does not have this feature".
 *
 * So: the reader's own locale is tried first and wins any tie, and only if NOTHING clears the floor
 * are the other supported locales tried. The answer is still rendered in the reader's language,
 * because a match returns a KEY and the guide is resolved from that key at render time — the same
 * rule the PDFs follow. A cross-locale match therefore never produces a half-translated screen.
 *
 * ## Every question is recorded, answered or not
 *
 * The unanswered ones are the point. See {@see AssistantQuestion}.
 */
class AnswerQuestionService
{
    /**
     * Below this, a match is noise rather than an answer.
     *
     * TWICE the `purpose` weight, which means: one TITLE or KEYWORD hit, or two independent hits on
     * a screen's purpose sentence. Nothing less.
     *
     * It was one purpose-weight, and measuring it against the demo books is what moved it. "Where
     * do I set the late fee cap" answered *AR aging* and «مين عليه فلوس» answered *Credit notes*,
     * both on a score of 3 — a single common word landing in a purpose sentence, presented with the
     * same confidence as a title match scoring 16. A wrong first result is worse than none: the
     * reader follows it, finds the wrong screen, and concludes the box does not work. No answer is
     * honest, costs one more search, and lands the question on the unanswered list where it becomes
     * the next screen guide.
     *
     * A single BODY hit (weight 1) was never enough — every long guide contains almost every common
     * word, so a floor of 1 answers every question with the longest guide in the system.
     */
    public const MINIMUM_SCORE = AssistantCorpus::WEIGHT_PURPOSE * 2;

    public const MAX_RESULTS = 5;

    /**
     * @return array{results: array<int, array{kind: string, key: string, screen: class-string, title: string, score: int}>, matched: bool, locale: string}
     */
    public function answer(string $question, ?int $assetId = null): array
    {
        $readerLocale = app()->getLocale();
        $words = $this->meaningfulWords($question);

        if ($words === []) {
            return $this->record($question, [], $readerLocale, $assetId);
        }

        $results = $this->rank($words, $readerLocale);

        // Nothing in the reader's language. Try the others before giving up — see the class
        // docblock. Ordered so the reader's own locale is never re-tried, and the FIRST locale that
        // produces anything wins rather than merging them, because merged rankings from two
        // languages are not comparable: the same screen would appear twice with different scores.
        if ($results === []) {
            foreach (SetLocale::SUPPORTED as $locale) {
                if ($locale === $readerLocale) {
                    continue;
                }

                $results = $this->rank($words, $locale);

                if ($results !== []) {
                    break;
                }
            }
        }

        return $this->record($question, $results, $readerLocale, $assetId);
    }

    /**
     * The words worth matching on — folded exactly as the corpus was, with the stop words removed.
     *
     * Folding the query with the same `SearchText` the corpus used is the whole reason «فاتوره»
     * finds «فاتورة». Folding one side only matches nothing, which is the failure this project
     * already states for every other search.
     *
     * @return array<int, string>
     */
    private function meaningfulWords(string $question): array
    {
        return array_values(array_filter(
            SearchText::words($question),
            fn (string $word): bool => ! in_array($word, AssistantCorpus::STOP_WORDS, true),
        ));
    }

    /**
     * @param  array<int, string>  $words
     * @return array<int, array{kind: string, key: string, screen: class-string, title: string, score: int}>
     */
    private function rank(array $words, string $locale): array
    {
        $scored = [];

        foreach (AssistantCorpus::entries($locale) as $entry) {
            $result = $entry->scoreAgainst($words);

            if ($result['score'] < self::MINIMUM_SCORE) {
                continue;
            }

            if (! $this->readerMayOpen($entry)) {
                continue;
            }

            $scored[] = [
                'entry' => $entry,
                'score' => $result['score'],
                'hits' => $result['hits'],
            ];
        }

        // Score, then how many of the typed words were hit, then title — the last only so the order
        // is stable. An unstable order on equal scores makes the same question answer differently
        // on two page loads, which reads as a bug in the data.
        usort($scored, function (array $a, array $b): int {
            return [$b['score'], $b['hits'], $a['entry']->title]
                <=> [$a['score'], $a['hits'], $b['entry']->title];
        });

        return array_map(
            fn (array $row): array => [
                'kind' => $row['entry']->kind,
                'key' => $row['entry']->key,
                'screen' => $row['entry']->screen,
                'title' => $row['entry']->title,
                'score' => $row['score'],
            ],
            array_slice($scored, 0, self::MAX_RESULTS),
        );
    }

    /**
     * Whether the signed-in reader could actually open this screen.
     *
     * `rescue()`d to FALSE: a `canAccess()` that throws is a screen whose access cannot be
     * established, and the safe reading of that is "no". Failing open here would be a permission
     * bypass reached through a search box.
     */
    private function readerMayOpen(AssistantEntry $entry): bool
    {
        return rescue(
            fn (): bool => (bool) $entry->screen::canAccess(),
            false,
            report: false,
        );
    }

    /**
     * @param  array<int, array{kind: string, key: string, screen: class-string, title: string, score: int}>  $results
     * @return array{results: array<int, array{kind: string, key: string, screen: class-string, title: string, score: int}>, matched: bool, locale: string}
     */
    private function record(string $question, array $results, string $locale, ?int $assetId): array
    {
        $top = $results[0] ?? null;

        AssistantQuestion::create([
            // The panel's tenant when the caller did not state one. Passed explicitly rather than
            // read here so a console replay of the miss list can attribute rows honestly.
            'asset_id' => $assetId ?? TenantScope::currentAssetId(),
            'user_id' => Auth::id(),
            // The column is 500 wide and the textarea is capped at 300; truncated anyway, because a
            // crafted payload must not be able to turn a logged question into a 500.
            'question' => mb_substr($question, 0, 500),
            'question_folded' => mb_substr(SearchText::normalize($question), 0, 500),
            'locale' => $locale,
            'matched' => $top !== null,
            'top_kind' => $top['kind'] ?? null,
            'top_key' => $top['key'] ?? null,
            'top_score' => $top['score'] ?? 0,
            'result_count' => count($results),
        ]);

        return [
            'results' => $results,
            'matched' => $top !== null,
            'locale' => $locale,
        ];
    }
}
