<?php

namespace App\Services\Assistant;

use App\Http\Middleware\SetLocale;
use App\Models\AssistantDocChunk;
use App\Models\AssistantQuestion;
use App\Support\Assistant\AssistantCorpus;
use App\Contracts\AssistantModel;
use App\Support\Assistant\AssistantBudget;
use App\Services\Assistant\Models\AssistantPrompt;
use App\Support\Assistant\AssistantDocs;
use App\Support\Assistant\TaskCorpus;
use App\Support\Assistant\AssistantEntry;
use App\Support\Assistant\AssistantRecords;
use App\Support\ReportCatalogue;
use App\Support\ScreenGuides;
use App\Support\ReportParameters;
use App\Support\Search\SearchText;
use Illuminate\Support\Facades\Cache;
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

    /** How many earlier exchanges the model is shown, so a follow-up makes sense. */
    public const CONTEXT_TURNS = 3;

    /**
     * @return array{results: array<int, array{kind: string, key: string, screen: string, title: string, score: int, url: string|null}>, matched: bool, locale: string}
     */
    public function answer(string $question, ?int $assetId = null, ?string $conversationId = null): array
    {
        $readerLocale = app()->getLocale();
        $words = $this->meaningfulWords($question);

        if ($words === []) {
            return $this->record($question, [], $readerLocale, $assetId, null, $conversationId);
        }

        $results = $this->rank($words, $readerLocale);

        // A FOLLOW-UP CARRIES NO NOUNS, and without this it retrieves nothing at all.
        //
        // "And how do I apply it?" is a perfectly ordinary second question, and every word in it is
        // either a stop word or too weak to clear the floor — so the search found nothing, the
        // model was never called, and the reader got "no sources" to a question the assistant had
        // just answered the first half of. Re-asking with the PREVIOUS question's words attached is
        // what makes it a conversation rather than a series of unrelated lookups.
        //
        // Only when the question alone found nothing, so a self-contained question is never
        // polluted by whatever was asked before it.
        if ($results === [] && $conversationId !== null) {
            $results = $this->rank(
                array_values(array_unique(array_merge($this->earlierWords($conversationId), $words))),
                $readerLocale,
            );
        }

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

        // RECORDS FIRST, and only the words the documentation has never heard of are searched for
        // (see AssistantRecords). A question that names a specific record — "how much does Zara
        // owe", a pasted invoice number — is asking about that record; the screen explaining the
        // concept is the follow-up, not the answer.
        // A YEAR IS NOT AN IDENTIFIER. "Income statement 2026" has one word the documentation
        // does not know — `2026` — and searching records for it matched three UNITS, because a
        // unit's search blob carries dates. A bare four-digit year is the one token that is
        // certainly not a record name, and it is already being read as a report parameter three
        // lines down. Document numbers survive: `INV-AW-202608-0417` folds to `invaw2026080417`,
        // which is not four digits.
        $unknown = array_values(array_filter(
            AssistantRecords::unknownWords($words, AssistantCorpus::entries($readerLocale)),
            fn (string $word): bool => ! preg_match('/^20\\d{2}$/', $word),
        ));

        $records = AssistantRecords::find($unknown);

        $screens = $this->mergeDuplicateDestinations($results);

        // THE DOCUMENTATION TIER — a fallback when nobody is wording the answer, and a SOURCE
        // when somebody is.
        //
        // With no model, a screen guide beats prose: it links to the screen that does the job,
        // where a paragraph only describes it, so ranking them together would let a well-written
        // chapter push the actual screen off the top. That rule is right for a list of results and
        // wrong for a chat: when the model is writing the answer, more grounding is strictly
        // better, and the screen link survives as a citation underneath rather than competing for
        // the top slot.
        $wording = app(AssistantModel::class)->isConfigured();

        // The fallback asks whether anything EXPLAINED the question, and a task explains nothing —
        // it is a link to a form. Counting one as an answer let "what happens when a cheque
        // bounces" match the post-dated-cheque FORM and silence the walkthrough that actually
        // answers it.
        $explanatory = array_filter(
            $screens,
            fn (array $r): bool => in_array($r['kind'] ?? '', ['screen', 'report'], true),
        );

        $docs = ($explanatory === [] || $wording)
            ? AssistantDocs::find($words, $readerLocale)
            : [];

        $results = array_slice(
            $this->withoutRepeatedTitles(array_merge($records, $screens, $docs)),
            0,
            self::MAX_RESULTS,
        );

        // THE WORDING LAYER (phase B), and it is the LAST thing that happens.
        //
        // Everything above already produced a usable answer; this only puts it into a sentence. So
        // it can be off, unconfigured, over budget or broken and the screen still works — which is
        // the property that let phase B ship before anyone decided to pay for it.
        $worded = $this->word($question, $results, $readerLocale, $conversationId);

        return $this->record($question, $results, $readerLocale, $assetId, $worded, $conversationId);
    }

    /**
     * The words of the previous question in this thread, for a follow-up that names nothing.
     *
     * @return array<int, string>
     */
    private function earlierWords(?string $conversationId): array
    {
        if ($conversationId === null) {
            return [];
        }

        $previous = AssistantQuestion::query()
            ->where('conversation_id', $conversationId)
            ->latest('id')
            ->value('question');

        return $previous === null ? [] : $this->meaningfulWords((string) $previous);
    }

    /**
     * The last few turns of this thread, as text the model reads the question against.
     *
     * **Context for the QUESTION, never a source of facts.** "And how do I issue it?" is
     * unanswerable without knowing what "it" was, and that is all this supplies — the system prompt
     * still forbids answering from anything but the passages, which are retrieved fresh every turn.
     * Bounded to the last few exchanges, because an unbounded history is how a chat quietly becomes
     * expensive on a metered model.
     */
    private function earlierTurns(?string $conversationId): string
    {
        if ($conversationId === null) {
            return '';
        }

        $turns = AssistantQuestion::query()
            ->where('conversation_id', $conversationId)
            ->whereNotNull('model_answer')
            ->latest('id')
            ->limit(self::CONTEXT_TURNS)
            ->get()
            ->reverse();

        if ($turns->isEmpty()) {
            return '';
        }

        return "<earlier_in_this_conversation>\n".$turns
            ->map(fn (AssistantQuestion $t): string => "Q: {$t->question}\nA: {$t->model_answer}")
            ->implode("\n")."\n</earlier_in_this_conversation>";
    }

    /**
     * Put the answer into a sentence, if a model is configured and there is budget left.
     *
     * **Cached on the question and on what retrieval found**, not on the question alone: if the
     * documentation is re-indexed or a screen guide is rewritten, the passages change and so must
     * the answer. Keyed by fold, so six people asking "izzay a3mel credit note" in six spellings
     * pay once — the cheapest question is the one that never reaches the API.
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return array{text: string|null, input: int, output: int}
     */
    private function word(string $question, array $results, string $locale, ?string $conversationId = null): array
    {
        $none = ['text' => null, 'input' => 0, 'output' => 0];

        $model = app(AssistantModel::class);

        if ($results === [] || ! $model->isConfigured()) {
            return $none;
        }

        $passages = [];

        foreach ($results as $result) {
            $body = $result['excerpt'] ?? null;

            // A REPORT needs its guide too, and reading only `kind === 'screen'` is why it did not
            // get one. Measured: «مين عليه فلوس» and «ازاي اعمل اشعار خصم» both matched a report and
            // nothing else, so no passage was built, so the model was never called — the screen
            // silently fell back to phase A while everything else said the model was on.
            //
            // Resolved through `ScreenGuides::keyFor()` on the SCREEN CLASS rather than trusting
            // `$result['key']`: for a screen those are the same string, and for a report the key is
            // the report's own (`ar_aging`), which is only equal to the guide key by coincidence.
            // A TASK's passage is the form itself: what it asks for, and which of it is required.
            // That is the half no guide carries, and the reason "what fields are on an invoice"
            // used to be answered with a paragraph about invoices.
            if ($body === null && ($result['kind'] ?? null) === 'task') {
                $body = trim(implode(' ', [
                    TaskCorpus::fieldSentence((string) $result['key']),
                    ScreenGuides::has((string) $result['key'])
                        ? implode(' ', ScreenGuides::steps((string) ScreenGuides::keyFor((string) $result['key'])))
                        : '',
                ]));
            }

            $guideKey = ScreenGuides::keyFor($result['screen'] ?? '');

            if ($body === null && $guideKey !== null) {
                // A screen's guide IS its passage — the same four fields the panel renders.
                $body = implode(' ', array_merge(
                    [ScreenGuides::purpose($guideKey)],
                    ScreenGuides::steps($guideKey),
                    ScreenGuides::rules($guideKey),
                ));
            }

            if (filled($body)) {
                $passages[] = ['title' => (string) $result['title'], 'body' => (string) $body];
            }
        }

        if ($passages === []) {
            return $none;
        }

        // THE PROMPT AND THE MODEL ARE PART OF THE KEY.
        //
        // Without them a cached answer outlives the thing that produced it: I improved the system
        // prompt, re-asked the question that motivated the change, and got the old answer back —
        // the model was never called, and the fix would not have reached anybody for the whole
        // 168-hour TTL. A cache that survives a change to its own inputs is not a cache, it is a
        // stale copy with a timer.
        //
        // Fingerprinted rather than versioned by hand, so editing the prompt is the whole change
        // and there is no second number to remember to bump.
        $earlier = $this->earlierTurns($conversationId);

        $key = 'assistant:answer:'.md5(implode('|', [
            $locale,
            SearchText::normalize($question),
            // A follow-up means something different after a different question. Without the thread
            // in the key, "and how do I issue it?" would be answered from whatever the FIRST person
            // to ask it happened to be talking about.
            md5($earlier),
            implode(',', array_column($results, 'key')),
            (string) config('assistant.driver'),
            (string) config('assistant.'.config('assistant.driver').'.model'),
            md5(AssistantPrompt::instructions($locale)),
        ]));

        if (($hit = Cache::get($key)) !== null) {
            // A cached answer cost nothing this time, and must not be counted against the ceiling
            // a second time — the tokens were already recorded on the row that paid for them.
            return ['text' => $hit, 'input' => 0, 'output' => 0];
        }

        // Checked BEFORE the call, so the ceiling is a wall and not a report. Deliberately after
        // the cache, so a month that has hit its limit still answers every question it has already
        // paid for.
        if (! AssistantBudget::allowsAnotherCall()) {
            return $none;
        }

        $text = $model->word($earlier === '' ? $question : $earlier."\n\n".$question, $passages, $locale);
        $usage = $model->lastUsage();

        if ($text !== null) {
            Cache::put($key, $text, now()->addHours((int) config('assistant.cache_hours')));
        }

        return ['text' => $text, 'input' => (int) $usage['input'], 'output' => (int) $usage['output']];
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
        $words = array_values(array_filter(
            SearchText::words($question),
            fn (string $word): bool => ! in_array($word, AssistantCorpus::STOP_WORDS, true),
        ));

        // The stem travels WITH the word, never instead of it — so an exact match still scores full
        // weight and this can only add a way in. The corpus indexes both sides (see
        // AssistantCorpus::addTerms), which is what lets «إشعارات» meet «اشعار» and back again.
        foreach ($words as $word) {
            $stem = AssistantDocChunk::stem($word);

            if ($stem !== $word && $stem !== '' && ! in_array($stem, $words, true)) {
                $words[] = $stem;
            }
        }

        return $words;
    }

    /**
     * @param  array<int, string>  $words
     * @return array<int, array{kind: string, key: string, screen: string, title: string, score: int, url: string|null}>
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
                // The rarest word this entry matched on. Two entries can score identically and not
                // be equally good: matching a word used by one screen says more than matching one
                // used by thirty. Lower is better, so it is negated in the sort key.
                'rarity' => $this->rarestMatch($entry, $words, $locale),
            ];
        }

        // Score, then how many of the typed words were hit, then title — the last only so the order
        // is stable. An unstable order on equal scores makes the same question answer differently
        // on two page loads, which reads as a bug in the data.
        // A CREATE question prefers a create form, but only as a TIE-BREAK.
        //
        // The verbs are read here and nowhere else, deliberately. Scoring them put all sixty-one
        // task entries on the same score for any question containing "issue" or "open", which
        // crowded the real answer out of five slots. Ordering equals cannot crowd out anything: it
        // only decides which of two things that already matched equally is shown first — which is
        // exactly the difference between "How do I generate an invoice" leading with **New
        // Invoice** and leading with **Custom fields**, both of which scored 8.
        $wantsToCreate = $this->looksLikeCreating($words);

        // A create question LIFTS the create forms, and the lift is small on purpose.
        //
        // It cannot introduce a match — every entry here already cleared the floor on its own
        // terms — so it only reorders things that were all going to be shown anyway. That is the
        // difference between "How do I generate an invoice and what fields does it need" leading
        // with **New Invoice** and leading with **Custom fields**, which matched on the word
        // "fields" and is a different feature entirely.
        if ($wantsToCreate) {
            foreach ($scored as $i => $row) {
                if ($row['entry']->kind === 'task') {
                    $scored[$i]['score'] += AssistantCorpus::WEIGHT_PURPOSE;
                }
            }
        }

        usort($scored, function (array $a, array $b): int {
            return [$b['score'], $b['hits'], $a['rarity'], $a['entry']->title]
                <=> [$a['score'], $a['hits'], $b['rarity'], $b['entry']->title];
        });

        return array_map(
            fn (array $row): array => [
                'kind' => $row['entry']->kind,
                'key' => $row['entry']->key,
                'screen' => $row['entry']->screen,
                'title' => $row['entry']->title,
                'score' => $row['score'],
                'url' => $this->urlFor($row['entry'], $words),
            ],
            array_slice($scored, 0, self::MAX_RESULTS),
        );
    }

    /**
     * One TITLE, one entry.
     *
     * Distinct from `mergeDuplicateDestinations()`, which folds one PAGE appearing as both a
     * guided screen and a catalogued report. This folds one NAME appearing from different places:
     * with the model on, the documentation tier always runs, so "Credit Notes" came back as a
     * screen and again as a handbook heading, and "3. Business rules & invariants" came back twice
     * from two different module files. As a list that is noise; as citations under a chat answer it
     * reads as though the assistant is repeating itself.
     *
     * Folded through `SearchText` so two spellings of one Arabic title collapse too, and
     * first-wins, so the higher-ranked entry — which is the one carrying the guide and the link —
     * survives.
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, array<string, mixed>>
     */
    private function withoutRepeatedTitles(array $results): array
    {
        $seen = [];
        $kept = [];

        foreach ($results as $result) {
            $title = SearchText::normalize((string) ($result['title'] ?? ''));

            if ($title === '' || isset($seen[$title])) {
                continue;
            }

            $seen[$title] = true;
            $kept[] = $result;
        }

        return $kept;
    }

    /**
     * One destination, one card.
     *
     * A page can be BOTH a screen with a guide and a catalogued report with keywords — the income
     * statement is both — so a question naming it produced two results with the same title, one
     * carrying the explanation and the other the year-filtered link. Two cards for one page reads
     * as a duplicate, and the reader has to open both to find which is which.
     *
     * Merged keeping the SCREEN's identity (its guide key is what renders the explanation) and the
     * best URL either side produced (the report's, when a year was named). Highest score wins the
     * ordering, so merging never demotes a page below one it out-ranked.
     *
     * @param  array<int, array{kind: string, key: string, screen: string, title: string, score: int, url: string|null}>  $results
     * @return array<int, array{kind: string, key: string, screen: string, title: string, score: int, url: string|null}>
     */
    private function mergeDuplicateDestinations(array $results): array
    {
        $merged = [];

        foreach ($results as $result) {
            $key = $result['screen'];

            if (! isset($merged[$key])) {
                $merged[$key] = $result;

                continue;
            }

            $kept = $merged[$key];

            $merged[$key] = [
                // The screen half owns the identity: its key is what resolves the four-field guide.
                ...($kept['kind'] === 'screen' ? $kept : $result),
                'score' => max($kept['score'], $result['score']),
                // A parameterised link is strictly more useful than the bare page, and only the
                // report half can produce one.
                'url' => $this->betterUrl($kept['url'], $result['url']),
            ];
        }

        return array_values($merged);
    }

    private function betterUrl(?string $a, ?string $b): ?string
    {
        // "Better" means "carries a query string" — that is the year the question named.
        foreach ([$a, $b] as $url) {
            if ($url !== null && str_contains($url, '?')) {
                return $url;
            }
        }

        return $a ?? $b;
    }

    /**
     * How rare the rarest word this entry matched on is. Lower is more distinctive.
     *
     * @param  array<int, string>  $words
     */
    private function rarestMatch(AssistantEntry $entry, array $words, string $locale): int
    {
        $rarest = PHP_INT_MAX;

        foreach ($words as $word) {
            if (($entry->terms[$word] ?? 0) > 0) {
                $rarest = min($rarest, AssistantCorpus::documentFrequency($locale, $word));
            }
        }

        return $rarest;
    }

    /**
     * Where a result leads — and for a report, at the year the question named.
     *
     * A YEAR is the only parameter safe to lift out of a question without guessing: four digits in
     * a plausible range are unambiguous in both languages, where "last month" and «الشهر اللي فات»
     * are not. Nothing else is attempted, and NO report declares a tenant parameter at all — so
     * "AR aging for Zara" links to the record AND to the report, rather than to a report
     * pre-filtered in a way it does not support.
     *
     * Even a wrong year is recoverable in a way a wrong FIGURE would not be: this is a link, and
     * the report renders its own period selector showing what it was opened at.
     *
     * @param  array<int, string>  $words
     */
    private function urlFor(AssistantEntry $entry, array $words): ?string
    {
        return rescue(function () use ($entry, $words): ?string {
            $year = $this->yearIn($words);

            if ($entry->kind === 'report'
                && $year !== null
                && isset(ReportCatalogue::REPORTS[$entry->screen])
                && array_key_exists('year', ReportParameters::parametersOf($entry->screen))) {
                return ReportParameters::urlFor($entry->screen, ['year' => $year]);
            }

            return $entry->screen::getUrl();
        }, null, report: false);
    }

    /**
     * @param  array<int, string>  $words
     */
    private function yearIn(array $words): ?int
    {
        foreach ($words as $word) {
            if (preg_match('/^20\d{2}$/', $word) && (int) $word <= 2100) {
                return (int) $word;
            }
        }

        return null;
    }

    /**
     * Does this question sound like somebody about to make something?
     *
     * @param  array<int, string>  $words
     */
    private function looksLikeCreating(array $words): bool
    {
        $verbs = SearchText::words((string) __('admin.assistant.task.verbs'));

        return array_intersect($words, $verbs) !== [];
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
            function () use ($entry): bool {
                // A TASK is asked of the RESOURCE, not of the create page.
                //
                // Measured: a `viewer` — who may create nothing anywhere — was offered "New
                // invoice" with a link straight to the form. The page's own `canAccess()` answered
                // true, so the existing check waved it through, and the refusal only arrived after
                // the click. `canCreate()` is the right question and the one the button itself asks.
                //
                // Checked HERE rather than while building the corpus deliberately: the corpus is
                // memoised per locale and shared by every request, so filtering it by the current
                // user would hand the next reader whatever the previous one was allowed to see.
                if ($entry->kind === 'task') {
                    return (bool) $entry->key::canCreate();
                }

                return (bool) $entry->screen::canAccess();
            },
            false,
            report: false,
        );
    }

    /**
     * @param  array<int, array{kind: string, key: string, screen: string, title: string, score: int, url: string|null}>  $results
     * @return array{results: array<int, array{kind: string, key: string, screen: string, title: string, score: int, url: string|null}>, matched: bool, locale: string}
     */
    private function record(string $question, array $results, string $locale, ?int $assetId, ?array $worded = null, ?string $conversationId = null): array
    {
        $top = $results[0] ?? null;

        $row = AssistantQuestion::create([
            'conversation_id' => $conversationId,
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
            'model_answer' => $worded['text'] ?? null,
            'model_input_tokens' => $worded['input'] ?? 0,
            'model_output_tokens' => $worded['output'] ?? 0,
        ]);

        return [
            'results' => $results,
            'matched' => $top !== null,
            'locale' => $locale,
            'answer' => $worded['text'] ?? null,
            // The turn's own id, so a rating lands on the answer it judges rather than on
            // "the most recent question", which is a different row the moment two people ask at
            // once.
            'id' => $row->id,
        ];
    }
}
