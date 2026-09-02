<?php

namespace App\Support\Assistant;

use App\Support\ReportCatalogue;
use App\Support\ScreenGuides;
use App\Support\Search\SearchText;

/**
 * What the assistant knows, DERIVED from the two registries that already hold it.
 *
 * Nothing here is a list of screens. `ScreenGuides::SCREENS` maps every screen in the panel to a
 * four-field guide, and `ScreenGuideConformanceTest` already fails the build on a screen with no
 * entry — so a new screen becomes searchable the day somebody writes its guide, with no second
 * registry to remember. `ReportCatalogue::REPORTS` does the same for the 26 reports, and its
 * `keywords` are hand-curated synonyms an operator would actually type ("ageing", "arrears",
 * "debtors" for AR aging) — the single most valuable signal in this corpus, and it was already
 * there, read by the report hub's filter and nothing else.
 *
 * ## Weights
 *
 * A word in a screen's TITLE or in a report's curated keywords is a strong signal; a word in the
 * `purpose` sentence is a fair one; a word buried in the steps is weak. Those three tiers are the
 * whole ranking model, and they are deliberately legible: an operator who gets a wrong answer must
 * be answerable with "it matched on this word", and a scoring scheme nobody can explain is one
 * nobody can fix.
 *
 * ## Stop words
 *
 * `SearchText::words()` does not drop short tokens — correct for its own job, where a two-letter
 * unit code is a real query. Here it means "how do I add a tenant" scores every guide that contains
 * the word "do". The list below is the minimum that changes rankings, in both languages, and it is
 * applied to the CORPUS as well as to the query: a stop word carrying weight is what lets a long
 * guide out-rank a precise title.
 *
 * ## Locale
 *
 * The guides are bilingual, so the corpus is per-locale and memoised per-locale. It is NOT
 * memoised per-user: the text is documentation and identical for everyone. Only the ACCESS filter
 * is per-person, and that lives in the service — a corpus that varied by user could not be cached
 * at all.
 */
final class AssistantCorpus
{
    public const WEIGHT_TITLE = 8;

    public const WEIGHT_KEYWORD = 8;

    public const WEIGHT_PURPOSE = 3;

    public const WEIGHT_BODY = 1;

    /**
     * Words that carry no signal in either language.
     *
     * Kept short on purpose. A long stop list starts deleting real query terms — "cost", "type"
     * and "open" all look like noise and all name things in this system.
     */
    public const STOP_WORDS = [
        // English
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by', 'can', 'do', 'does', 'for', 'from',
        'has', 'have', 'how', 'i', 'if', 'in', 'is', 'it', 'its', 'me', 'my', 'not', 'of', 'on',
        'or', 'so', 'than', 'that', 'the', 'their', 'them', 'then', 'there', 'these', 'they',
        'this', 'to', 'was', 'what', 'when', 'where', 'which', 'who', 'why', 'will', 'with', 'you',
        'your',
        // Added after the first run of `AskingAtriomFindsTheScreenThatAnswersTest`, which caught a
        // real one: the report hub's own label is "All Reports", so `all` carried TITLE weight (8)
        // and any sentence containing the word — "show me all the…" — got a confident top hit on
        // the report hub. The floor cannot catch that, because the floor exists to suppress weak
        // BODY matches and this was the strongest weight in the corpus. The stop list can, and it
        // is applied to the corpus as well as to the query, so the term stops existing on both
        // sides and "Reports" is still what finds that screen. Safe here specifically because
        // All-Properties mode was removed — `all` names nothing in this system any more.
        'all', 'any', 'no', 'nothing', 'anything', 'something', 'each', 'every', 'both', 'other',
        'same', 'such', 'own', 'very', 'just', 'here', 'now', 'been', 'being', 'were', 'had',
        'would', 'should', 'could', 'may', 'must', 'we', 'us', 'our', 'he', 'she', 'him', 'her',
        'his', 'happens', 'happen', 'want', 'need', 'know', 'tell', 'show', 'give',
        // Arabic (folded — see SearchText::normalize)
        'من', 'في', 'على', 'الى', 'عن', 'مع', 'هذا', 'هذه', 'ذلك', 'التي', 'الذي', 'ما', 'ماذا',
        'كيف', 'اين', 'متى', 'هل', 'او', 'ثم', 'كل', 'بعد', 'قبل', 'عند', 'يتم', 'ان', 'انا',
        // Egyptian colloquial. An operator does not type «كيف» — they type «ازاي». Folded
        // forms: `SearchText::normalize()` strips the hamza, so «إزاي» and «ازاي» are one word
        // here and only the bare spelling needs listing.
        'ازاي', 'ايه', 'فين', 'مين', 'ليه', 'امتى', 'عايز', 'عاوز', 'عندي', 'اعمل', 'ازيك',
    ];

    /** @var array<string, array<int, AssistantEntry>> */
    private static array $memo = [];

    /**
     * Every entry, in the given locale.
     *
     * @return array<int, AssistantEntry>
     */
    public static function entries(string $locale): array
    {
        if (isset(self::$memo[$locale])) {
            return self::$memo[$locale];
        }

        // THE LOCALE WRAPS THE DATA RESOLUTION, NOT JUST THE OUTPUT.
        //
        // Every string this corpus is built from — `ScreenGuides::purpose()`,
        // `ReportCatalogue::titleOf()`, each screen's `getNavigationLabel()` — resolves through
        // `__()` against whatever locale is CURRENT. So a `$locale` argument that does not switch
        // the application locale is decoration: it would build the Arabic corpus out of English
        // strings and the cross-locale fallback would silently compare English to English, always
        // finding nothing new. The same trap `DocumentLocale::in()` exists for on the PDFs.
        //
        // `finally`, so a throwing guide cannot leave the operator's panel switched into the other
        // language for the rest of the request.
        $previous = app()->getLocale();

        try {
            app()->setLocale($locale);

            self::$memo[$locale] = array_merge(
                self::screenEntries(),
                self::reportEntries(),
                // "Create one, and here is what the form asks for" — read from the forms
                // themselves, so it cannot drift from the screen it describes.
                TaskCorpus::entries(),
            );
        } finally {
            app()->setLocale($previous);
        }

        return self::$memo[$locale];
    }

    /**
     * Drop the memo. Only the tests need this — a request has one locale and one corpus.
     */
    public static function flush(): void
    {
        self::$memo = [];
    }

    /**
     * @return array<int, AssistantEntry>
     */
    private static function screenEntries(): array
    {
        $entries = [];

        foreach (ScreenGuides::SCREENS as $screen => $key) {
            // The portal has its own guides, written for a retailer rather than an operator. The
            // admin assistant must never offer one: it would answer an operator's question with an
            // explanation of a screen they cannot open, in words aimed at somebody else.
            if (str_starts_with($key, 'portal_') || str_contains($screen, '\\Portal\\')) {
                continue;
            }

            // And not itself. "Ask Atriom" answering a question by suggesting you open Ask Atriom
            // is a dead end, and its own guide is full of the vocabulary of asking questions —
            // which is exactly the vocabulary every question is made of, so it would rank on
            // everything.
            if ($key === 'assistant') {
                continue;
            }

            $terms = [];

            self::addTerms($terms, self::titleOf($screen), self::WEIGHT_TITLE);
            self::addTerms($terms, ScreenGuides::purpose($key), self::WEIGHT_PURPOSE);

            foreach ([ScreenGuides::steps($key), ScreenGuides::affects($key), ScreenGuides::rules($key)] as $lines) {
                foreach ($lines as $line) {
                    self::addTerms($terms, $line, self::WEIGHT_BODY);
                }
            }

            $entries[] = new AssistantEntry(
                kind: 'screen',
                key: $key,
                screen: $screen,
                title: self::titleOf($screen),
                terms: $terms,
            );
        }

        return $entries;
    }

    /**
     * Reports get their own entries even though most are also screens with guides.
     *
     * Two different answers to two different questions: "what is the AR aging screen" is a guide,
     * "show me who owes money" is a report to open. The curated `keywords` are what make the second
     * one work, and they only exist here.
     *
     * @return array<int, AssistantEntry>
     */
    private static function reportEntries(): array
    {
        $entries = [];

        foreach (ReportCatalogue::REPORTS as $page => $meta) {
            $terms = [];

            $title = ReportCatalogue::titleOf($page, $meta['key']);

            self::addTerms($terms, $title, self::WEIGHT_TITLE);

            foreach ($meta['keywords'] ?? [] as $keyword) {
                self::addTerms($terms, $keyword, self::WEIGHT_KEYWORD);
            }

            $entries[] = new AssistantEntry(
                kind: 'report',
                key: $meta['key'],
                screen: $page,
                title: $title,
                terms: $terms,
            );
        }

        return $entries;
    }

    /**
     * Fold a phrase and fold its weights into the map, keeping the HIGHEST weight per word.
     *
     * Highest rather than summed: a word appearing eleven times in a long guide would otherwise
     * out-weigh the same word in a title, which inverts the whole ranking — length would beat
     * relevance, and the longest guides would win every query.
     *
     * @param  array<string, int>  $terms
     */
    private static function addTerms(array &$terms, ?string $phrase, int $weight): void
    {
        foreach (SearchText::words($phrase) as $word) {
            if (in_array($word, self::STOP_WORDS, true)) {
                continue;
            }

            $terms[$word] = max($terms[$word] ?? 0, $weight);
        }
    }

    /**
     * The screen's own navigation label, so the assistant names a screen the way the sidebar does.
     *
     * `rescue()`d because a label can be a closure reaching for state a bare static call has not
     * set up, and one screen that throws must not take the whole corpus — and therefore the whole
     * assistant — down with it.
     */
    private static function titleOf(string $screen): string
    {
        return rescue(
            fn (): string => (string) $screen::getNavigationLabel(),
            fn (): string => class_basename($screen),
            report: false,
        );
    }
}
