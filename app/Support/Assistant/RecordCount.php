<?php

namespace App\Support\Assistant;

use App\Models\AssistantDocChunk;
use App\Support\AssistantFields;
use App\Support\Search\SearchText;
use App\Support\ValueSets;
use Filament\Facades\Filament;

/**
 * "How many leases are there, and how do they split by status?"
 *
 * ## A structured question, never a string
 *
 * There is no SQL here and none is accepted. The resource is chosen by RETRIEVAL — the same ranking
 * the evaluation set pins — and the only other input is a group-by column, which must be a
 * classification column this system has already registered in {@see ValueSets::forTable()}. That
 * registry exists because those columns have a closed set of values; using it here means a
 * group-by can only ever name a column the system already governs, and a question naming anything
 * else simply gets a total.
 *
 * The alternative — letting a model write `GROUP BY` — is refused for the four reasons in
 * docs/integrations/AI-ASSISTANT.md §1, and this is what replaces it: the same answer, reachable
 * only through columns somebody classified on purpose.
 *
 * ## Counting is done by the database, never by the model
 *
 * The model is shown "active: 12, expired: 3" and may read it. It may not add them up — the total
 * is a separate `count()` for exactly that reason, because a model summing a list it was shown is
 * arithmetic, and arithmetic about a portfolio is the thing this whole design keeps away from it.
 *
 * ## Scope is inherited
 *
 * Every count runs on `$resource::getEloquentQuery()`, so a restricted operator counts their own
 * mall's rows and nobody else's — the same query the list page runs, not a re-implementation of it.
 */
final class RecordCount
{
    /** Groups shown before the tail is stated. A split with forty buckets is a report, not a sentence. */
    public const MAX_GROUPS = 12;

    /**
     * Does this question ask how many of something there are?
     *
     * Read as INTENT only, exactly like the create verbs: never scored, so it cannot crowd the
     * ranking, and it decides whether to count rather than what to count.
     *
     * @param  array<int, string>  $words
     */
    public static function isCounting(array $words): bool
    {
        $verbs = SearchText::words((string) __('admin.assistant.count.verbs'));

        return array_intersect($words, $verbs) !== [];
    }

    /**
     * Does this question name a STATE of some register?
     *
     * "What is the pending invoices" carries no counting verb at all, and it is unambiguously a
     * question about a number — it was answered with the definition of an invoice. A named state is
     * the second way in, so the figure is produced for the question people actually type.
     *
     * @param  array<int, string>  $words
     */
    public static function namesAState(string $question): bool
    {
        $asked = self::asked($question);

        foreach (array_keys(RecordStates::CONCEPTS) as $key) {
            [$table, $column] = explode('.', $key, 2);

            if (array_intersect($asked, RecordStates::words($table, $column)) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $words
     * @return array{title: string, body: string}|null
     */
    public static function for(string $resource, array $words, string $question, bool $mustFilter = false): ?array
    {
        return rescue(function () use ($resource, $words, $question, $mustFilter): ?array {
            $model = $resource::getModel();

            // Deliberately the SAME allowlist that governs reading a record back. Counting rows of
            // a register nobody may quote is a smaller leak of the same kind — "how many employees
            // earn over X" is a question about people, answered by a screen with its own
            // permission.
            if (! AssistantFields::isSummarisable($model)) {
                return null;
            }

            if (! rescue(fn (): bool => (bool) $resource::canViewAny(), false, report: false)) {
                return null;
            }

            $table = (new $model)->getTable();
            $allowed = self::classificationColumns($model, $table);

            $total = $resource::getEloquentQuery()->count();
            $label = (string) $resource::getPluralModelLabel();

            // ── A NAMED STATE FILTERS, and an unnamed one is not silently dropped ───────────────
            //
            // "How many invoices are unpaid" answered **There are 2 Invoices in this property** —
            // the total, on a database whose two invoices are both PAID. The right answer was zero.
            // That is worse than no answer: it is a confident number about a question nobody asked,
            // and the reader has no way to see that the qualifier was thrown away.
            $narrowed = self::stateFilter($model, $table, $allowed, self::asked($question));

            if ($narrowed !== null) {
                $count = $resource::getEloquentQuery()
                    ->whereIn($narrowed['column'], $narrowed['values'])
                    ->count();

                return [
                    'title' => $label,
                    'body' => __('admin.assistant.count.in_state', [
                        'count' => $count,
                        'total' => $total,
                        'label' => $label,
                        'state' => $narrowed['label'],
                    ]),
                ];
            }

            // A state was named and could NOT be applied — so say nothing. Silence costs the reader
            // one more search; a total presented as the answer to a filtered question costs them a
            // decision made on the wrong figure.
            if (self::namesAnUnresolvedState($table, $allowed, self::asked($question))) {
                return null;
            }

            // NOTHING BUT A STATE BROUGHT US HERE, so a total is not an answer to anything.
            //
            // A named state makes the count reachable without a counting verb, which is what lets
            // "what is the pending invoices" produce a figure at all. The cost is that an ordinary
            // how-to question carrying a state word — "how do I make a unit available" — would
            // otherwise have the register's TOTAL pinned to the top of its answer, which is noise
            // presented with the authority of a measurement.
            if ($mustFilter) {
                return null;
            }

            $column = self::groupColumn($table, $allowed, $words);

            if ($column === null) {
                return ['title' => $label, 'body' => __('admin.assistant.count.total', ['label' => $label, 'count' => $total])];
            }

            $groups = $resource::getEloquentQuery()
                ->selectRaw("{$column} as bucket, COUNT(*) as n")
                ->groupBy($column)
                ->orderByDesc('n')
                ->limit(self::MAX_GROUPS)
                ->pluck('n', 'bucket');

            $parts = [];

            foreach ($groups as $bucket => $n) {
                // A NULL bucket rendered as an empty string — "By ETA status — : 2" — which reads
                // as a broken sentence rather than as "these rows have none". A nullable
                // classification is ordinary, so it is labelled, not hidden.
                $parts[] = ((string) $bucket === ''
                    ? __('admin.assistant.count.not_set')
                    : self::valueLabel($model, $column, (string) $bucket)).': '.$n;
            }

            return [
                'title' => $label,
                'body' => __('admin.assistant.count.total', ['label' => $label, 'count' => $total])
                    ."\n".__('admin.assistant.count.by', ['column' => self::columnLabel($column)])
                    .' '.implode(', ', $parts),
            ];
        }, null, report: false);
    }

    /**
     * The classification columns this assistant may group or filter by.
     *
     * `ValueSets::forTable()` narrowed to the columns `AssistantFields` already lets the assistant
     * QUOTE — one registry, not a second list, and it closes two holes at once:
     *
     *   * **A frozen module's column.** "How many invoices by status" grouped by `eta_status` and
     *     rendered *"By ETA status — : 2"*. ETA is in `Modules::FROZEN` and is deliberately
     *     invisible everywhere an operator looks; the assistant was the one surface still offering
     *     it, in the worst way — as an empty answer to a real question.
     *   * **A collision on the operator's own word.** `eta_status` has a value literally called
     *     `pending`, so the reported question — *"what is the pending invoices"* — would have been
     *     answered by filtering a frozen module's column. The two hazards share one fix.
     *
     * @return array<int, string>
     */
    private static function classificationColumns(string $model, string $table): array
    {
        $quotable = AssistantFields::columnsFor($model);

        return array_values(array_intersect(array_keys(ValueSets::forTable($table)), $quotable));
    }

    /**
     * The state a question names, as a column, a value set and a word for it.
     *
     * ## One contest, won by whichever candidate consumes MORE of the question
     *
     * Stored values and operator concepts compete on the same scale — the number of the reader's
     * own words the candidate accounts for — and that single rule replaces three that each got a
     * money answer wrong:
     *
     *   * **Registry order decided it.** `partially_paid` tokenises to "partially" + "paid", so it
     *     swallowed the word *paid* and won purely by being listed first: "how many invoices are
     *     paid" answered **0 of 2 Invoices are Partially Paid** where both were PAID. Now `paid`
     *     consumes one token of one, `partially_paid` one of two, and the exact word wins — while
     *     "partially paid invoices" still reaches `partially_paid`, which consumes both.
     *   * **A negation was ignored.** «غير مدفوعة» consumes two tokens against the value `paid`'s
     *     one, so the phrase wins instead of being overridden by the word inside it.
     *   * **A phrase leaked its positive half.** Handled in {@see RecordStates::match()}.
     *
     * @param  array<int, string>  $allowed
     * @param  array<int, string>  $asked  tokens of the RAW question, stop words included
     * @return array{column: string, values: array<int, string>, label: string}|null
     */
    private static function stateFilter(string $model, string $table, array $allowed, array $asked): ?array
    {
        $best = null;

        foreach ($allowed as $column) {
            foreach (ValueSets::forTable($table)[$column] ?? [] as $value) {
                $spellings = array_unique(array_merge(
                    AssistantCorpus::tokenise(str_replace('_', ' ', (string) $value)),
                    AssistantCorpus::tokenise(self::valueLabel($model, $column, (string) $value)),
                ));

                $consumed = count(array_intersect($asked, $spellings));

                if ($consumed === 0) {
                    continue;
                }

                $best = self::better($best, [
                    'column' => $column,
                    'values' => [(string) $value],
                    'label' => self::valueLabel($model, $column, (string) $value),
                    'consumed' => $consumed,
                    'width' => count($spellings),
                    'negating' => false,
                ]);
            }

            foreach (RecordStates::match($table, $column, $asked) as $concept) {
                $best = self::better($best, [
                    'column' => $column,
                    'values' => $concept['values'],
                    'label' => (string) __('admin.assistant.states.'.$concept['state']),
                    'consumed' => $concept['consumed'],
                    'width' => $concept['consumed'],
                    'negating' => $concept['negating'],
                ]);
            }
        }

        if ($best === null) {
            return null;
        }

        // ── A NEGATED QUESTION MAY NOT BE ANSWERED WITH THE POSITIVE VALUE ────────────────────
        //
        // "How many invoices are NOT paid" reaches the ranking as `[invoices, paid]`, because `not`
        // is a stop word — so without this the answer is the exact opposite of the question, stated
        // as a figure. When the reader negated, the value they named is the one they want EXCLUDED:
        // the concept on that column that leaves it out is the answer, and if this register has no
        // such concept the count says nothing at all rather than inverting.
        if (! $best['negating'] && RecordStates::isNegated($asked)) {
            foreach (RecordStates::conceptsFor($table, $best['column']) as $state => $concept) {
                if (array_intersect($concept['values'], $best['values']) === []) {
                    return [
                        'column' => $best['column'],
                        'values' => $concept['values'],
                        'label' => (string) __('admin.assistant.states.'.$state),
                    ];
                }
            }

            return null;
        }

        return ['column' => $best['column'], 'values' => $best['values'], 'label' => $best['label']];
    }

    /**
     * More of the question consumed wins; on a tie, the candidate with the narrower vocabulary.
     *
     * The tie-break matters on its own: "paid" and `partially_paid` both consume one token, and the
     * one whose whole vocabulary the reader typed is the better answer.
     *
     * @param  array{column: string, values: array<int, string>, label: string, consumed: int, width: int, negating: bool}|null  $best
     * @param  array{column: string, values: array<int, string>, label: string, consumed: int, width: int, negating: bool}  $next
     * @return array{column: string, values: array<int, string>, label: string, consumed: int, width: int, negating: bool}
     */
    private static function better(?array $best, array $next): array
    {
        if ($best === null) {
            return $next;
        }

        if ($next['consumed'] !== $best['consumed']) {
            return $next['consumed'] > $best['consumed'] ? $next : $best;
        }

        return $next['width'] < $best['width'] ? $next : $best;
    }

    /**
     * Did the reader name a state of this register that could not be turned into a filter?
     *
     * Only ever asked AFTER `stateFilter()` has failed, so reaching it means the word is one this
     * register uses and the count cannot honour it. The count then says nothing at all, which is
     * this codebase's standing rule about the assistant: a wrong first answer is worse than none,
     * because the reader acts on it.
     *
     * @param  array<int, string>  $allowed
     * @param  array<int, string>  $words
     */
    /**
     * The reader's question as tokens the matcher can meet, stop words INCLUDED.
     *
     * Stemmed as well as folded, for the reason the ranking already stems: «المعلقة» carries the
     * definite article and «معلقة» does not, so the reported question — «ما هي الفواتير المعلقة» —
     * matched no state at all and answered with the definition of an invoice.
     *
     * @return array<int, string>
     */
    private static function asked(string $question): array
    {
        $tokens = AssistantCorpus::tokenise($question);

        foreach ($tokens as $token) {
            $stem = AssistantDocChunk::stem($token);

            if ($stem !== $token && $stem !== '') {
                $tokens[] = $stem;
            }
        }

        return array_values(array_unique($tokens));
    }

    private static function namesAnUnresolvedState(string $table, array $allowed, array $words): bool
    {
        foreach ($allowed as $column) {
            if (array_intersect($words, RecordStates::words($table, $column)) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The column to split by — only ever one this system has already classified.
     *
     * @param  array<int, string>  $allowed
     * @param  array<int, string>  $words
     */
    private static function groupColumn(string $table, array $allowed, array $words): ?string
    {
        $best = null;
        $bestWidth = PHP_INT_MAX;

        foreach ($allowed as $column) {
            // Matched on the column's own LABEL as well as its name, because nobody types
            // `rent_pricing_basis` — they type "pricing basis", and the label is the words the
            // screen already uses for it.
            $candidates = array_merge(
                SearchText::words(str_replace('_', ' ', $column)),
                SearchText::words(self::columnLabel($column)),
            );

            if (array_intersect($words, $candidates) === []) {
                continue;
            }

            // THE NARROWEST MATCH WINS, never the first one registered. "Status" matches both
            // `status` and `eta_status`, and registry order decided it — which is no rule at all.
            // A column whose whole name the reader typed is a better answer than one where they
            // typed half of it.
            $width = count($candidates);

            if ($width < $bestWidth) {
                $best = $column;
                $bestWidth = $width;
            }
        }

        return $best;
    }

    /**
     * A stored code rendered in the reader's language.
     *
     * The status catalogue is keyed by the MODEL, singular — `admin.statuses.unit`, not
     * `admin.statuses.units` — which is the convention `ActivityVocabulary` already uses, and
     * getting it wrong is silent: the group renders "Vacant: 11" in an Arabic sentence, which is
     * the half-translated shape this codebase keeps finding. `admin.enums.*` is the second home,
     * for the classification columns that are not statuses.
     */
    private static function valueLabel(string $model, string $column, string $value): string
    {
        $singular = \Illuminate\Support\Str::snake(class_basename($model));

        foreach (["admin.statuses.{$singular}.{$value}", "admin.enums.{$column}.{$value}"] as $key) {
            if (trans()->has($key)) {
                return (string) __($key);
            }
        }

        return str_replace('_', ' ', $value);
    }

    private static function columnLabel(string $column): string
    {
        return trans()->has("admin.fields.{$column}")
            ? (string) __("admin.fields.{$column}")
            : str_replace('_', ' ', $column);
    }
}
