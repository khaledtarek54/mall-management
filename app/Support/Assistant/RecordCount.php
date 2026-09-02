<?php

namespace App\Support\Assistant;

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
     * @param  array<int, string>  $words
     * @return array{title: string, body: string}|null
     */
    public static function for(string $resource, array $words): ?array
    {
        return rescue(function () use ($resource, $words): ?array {
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
            $column = self::groupColumn($table, $words);

            $total = $resource::getEloquentQuery()->count();
            $label = (string) $resource::getPluralModelLabel();

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
                $parts[] = self::valueLabel($model, $column, (string) $bucket).': '.$n;
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
     * The column to split by — only ever one this system has already classified.
     *
     * @param  array<int, string>  $words
     */
    private static function groupColumn(string $table, array $words): ?string
    {
        foreach (array_keys(ValueSets::forTable($table)) as $column) {
            // Matched on the column's own LABEL as well as its name, because nobody types
            // `rent_pricing_basis` — they type "pricing basis", and the label is the words the
            // screen already uses for it.
            $candidates = array_merge(
                SearchText::words(str_replace('_', ' ', $column)),
                SearchText::words(self::columnLabel($column)),
            );

            if (array_intersect($words, $candidates) !== []) {
                return $column;
            }
        }

        return null;
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
