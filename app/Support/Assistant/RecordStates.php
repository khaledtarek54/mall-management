<?php

namespace App\Support\Assistant;

/**
 * The words operators use for a STATE that is not one of the stored values.
 *
 * `invoices.status` holds `issued`, `partially_paid` and `overdue`; nobody asks for those. They ask
 * what is **pending**, what is **unpaid**, what is still **outstanding** — one word covering three
 * values, and no amount of value-label matching can reach it because the word is not a value.
 *
 * This is the same lever as `admin.assistant.synonyms` one level down: that one teaches the words
 * for a SCREEN, this one the words for a STATE of the rows on it. Both languages live inline, the
 * way `ReportCatalogue::keywords` already does, because a concept and the words for it are one
 * fact and splitting them across a PHP registry and two lang files is three places to forget.
 *
 * ## Why a concept is a SET of values and never a query
 *
 * A concept expands to values this system has already registered in `ValueSets`, and to nothing
 * else. So it can never reach a column nobody classified, never invent a status, and never become
 * a filter somebody has to read SQL to check — `RecordStatesAreRealValuesTest` fails the build on
 * a value that is not in the column's own set.
 *
 * ## What is deliberately NOT here
 *
 * **No amount.** "How much is outstanding" is answered by the AR aging report, which nets write-offs
 * through `Invoice::collectableBalance()` and carries the ageing buckets. Summing `balance` here
 * would be a second truth about AR beside `ReportService` — the thing this whole design exists to
 * avoid — and it would quote money the operator has already forgiven. The count says HOW MANY; the
 * report says HOW MUCH.
 */
final class RecordStates
{
    /**
     * `table.column` => concept => {values, words}.
     *
     * **A word must SELECT the state** — the same rule `admin.assistant.synonyms` obeys one level
     * up, and this registry broke it on its first pass. `free` under *vacant* fired on "record a
     * rent free period"; `running` and `current` under *live* fired on "which tenants are running
     * out of cheques" and on the phrase "the current period"; `let` under *occupied* fired on "let
     * a parking bay" — every one of them a how-to question that would have had a figure about
     * something else pinned to the top of its answer. A word that reads as ordinary English
     * anywhere in this domain belongs nowhere near here.
     *
     * @var array<string, array<string, array{values: array<int, string>, words: array<int, string>}>>
     */
    public const CONCEPTS = [
        'invoices.status' => [
            // NOT `draft` — a draft is not a document and the tenant has never seen it, which is
            // the invariant `TenantVisibility` exists for; counting one as money owed would
            // overstate the debt. NOT `disputed`, `credited` or `written_off` either: each has
            // left the ordinary collection cycle by a decision somebody made, and lumping them in
            // is how a chase letter asks for money the operator themselves forgave.
            'unpaid' => [
                'values' => ['issued', 'partially_paid', 'overdue'],
                'words' => [
                    'unpaid', 'pending', 'outstanding', 'uncollected', 'unsettled', 'owing', 'owed',
                    'معلقة', 'معلق', 'غير مدفوعة', 'غير مسددة', 'مستحقة', 'لم تسدد', 'لم تدفع',
                ],
            ],
            'settled' => [
                'values' => ['paid'],
                'words' => ['settled', 'collected', 'مسددة', 'محصلة', 'تم السداد'],
            ],
        ],

        'credit_notes.status' => [
            // `issued` alone: a credit note is `draft` · `issued` · `applied` · `void`, with no
            // partial state — applying part of one leaves it `issued`, which is exactly why this
            // reads as "not yet used up" rather than "untouched".
            'unapplied' => [
                'values' => ['issued'],
                'words' => ['unapplied', 'unused', 'غير مطبقة', 'متبقية'],
            ],
        ],

        'leases.status' => [
            'live' => [
                'values' => ['active'],
                'words' => ['live', 'ongoing', 'ساري', 'سارية', 'قائم', 'قائمة'],
            ],
            'ended' => [
                'values' => ['expired', 'terminated'],
                'words' => ['ended', 'finished', 'منتهي', 'منتهية', 'منهاة'],
            ],
        ],

        'units.status' => [
            'empty' => [
                'values' => ['vacant'],
                'words' => ['empty', 'available', 'unlet', 'شاغر', 'شاغرة', 'فاضية', 'متاح', 'متاحة'],
            ],
            'let' => [
                'values' => ['occupied'],
                'words' => ['rented', 'مؤجر', 'مؤجرة', 'مشغول', 'مشغولة'],
            ],
        ],
    ];

    /**
     * The concepts a question names for this column, with how much of the question each consumed.
     *
     * ## A multi-word entry is a PHRASE, and every token has to be there
     *
     * Folding the word lists into one bag was wrong in the direction that inverts an answer.
     * «غير مسددة» ("not settled") is two tokens, and bagging them put **مسددة** — *settled* — into
     * the vocabulary of the UNPAID concept. Measured: «كم فاتورة مسددة» ("how many invoices are
     * settled") answered *0 of 2 are still unpaid* on a database where both were paid. A phrase
     * only counts when the reader typed all of it.
     *
     * The consumed count is what lets a phrase beat a bare value: «غير مدفوعة» consumes two tokens
     * where the value `paid` consumes the one, so the negation wins instead of being ignored.
     *
     * @param  array<int, string>  $asked
     * @return array<int, array{state: string, values: array<int, string>, consumed: int, negating: bool}>
     */
    public static function match(string $table, string $column, array $asked): array
    {
        $found = [];

        foreach (self::CONCEPTS["{$table}.{$column}"] ?? [] as $state => $concept) {
            $best = 0;
            $negating = false;

            foreach ($concept['words'] as $phrase) {
                $tokens = AssistantCorpus::tokenise($phrase);

                if ($tokens === [] || array_diff($tokens, $asked) !== []) {
                    continue;
                }

                if (count($tokens) >= $best) {
                    $negating = array_intersect($tokens, self::NEGATIONS) !== [];
                }

                $best = max($best, count($tokens));
            }

            if ($best > 0) {
                $found[] = [
                    'state' => $state,
                    'values' => $concept['values'],
                    'consumed' => $best,
                    // Whether the phrase the reader typed CARRIED the negation itself.
                    //
                    // «غير مدفوعة» already means "unpaid", so the negation is spent; «شاغرة»
                    // ("vacant") does not, so «غير شاغرة» has to be flipped or the answer is the
                    // opposite of the question. Without this the guard fired on bare values only,
                    // and «كم وحدة غير شاغرة» answered *10 of 12 Units are Vacant*.
                    'negating' => $negating,
                ];
            }
        }

        return $found;
    }

    /**
     * Every concept registered for this column, whether or not the question named one.
     *
     * @return array<string, array{values: array<int, string>, words: array<int, string>}>
     */
    public static function conceptsFor(string $table, string $column): array
    {
        return self::CONCEPTS["{$table}.{$column}"] ?? [];
    }

    /**
     * Every word this registry knows for the column, folded.
     *
     * Read to answer the OTHER question — "did the reader name a state at all?" — because a state
     * named and not applied is what turns a filtered question into an unfiltered number.
     *
     * @return array<int, string>
     */
    public static function words(string $table, string $column): array
    {
        $words = [];

        foreach (self::CONCEPTS["{$table}.{$column}"] ?? [] as $concept) {
            foreach ($concept['words'] as $phrase) {
                $words = array_merge($words, AssistantCorpus::tokenise($phrase));
            }
        }

        return array_values(array_unique($words));
    }

    /**
     * The particles that turn a question into its opposite, in both languages.
     *
     * Kept HERE rather than in a lang file because a negation is not chrome — it changes which rows
     * are counted — and because the two lists have to be read together: an English panel is asked
     * Arabic questions and back again, exactly as the act verbs are.
     *
     * **`not` and `no` are STOP WORDS**, stripped before any of the ranking sees them, which is
     * right for a bag-of-words corpus and catastrophic here: "how many invoices are not paid"
     * reached this class as `[invoices, paid]` and answered **2 of 2 Invoices are Paid** — the
     * opposite of the question, in figures, about money. So negation is read from the RAW question
     * and never from the words the ranking uses.
     */
    public const NEGATIONS = [
        'not', 'no', 'non', 'without', 'never', 'except', 'excluding',
        'غير', 'لم', 'ليس', 'ليست', 'بدون', 'مش', 'عدا',
    ];

    /**
     * @param  array<int, string>  $asked  tokens of the RAW question, stop words included
     */
    public static function isNegated(array $asked): bool
    {
        return array_intersect($asked, self::NEGATIONS) !== [];
    }
}
