<?php

namespace App\Support\Assistant;

use App\Support\AssistantFields;
use App\Support\Search\SearchText;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * The handful of facts about a record somebody named — "what does Cilantro owe".
 *
 * ## It searches through the RESOURCE, which is what makes it safe
 *
 * Every candidate comes from `$resource::getEloquentQuery()`, the same query the list page runs, so
 * the property scope and the permissions are inherited rather than re-implemented — and
 * `canViewAny()` is asked first, so a reader who cannot open the register never sees a row from it.
 * Nothing here decides visibility; it decides which COLUMNS of an already-visible record are worth
 * quoting, and that is {@see AssistantFields}.
 *
 * ## Why not reuse the record links the chat already shows
 *
 * `AssistantRecords` goes through Filament's global-search provider, which hands back a title and a
 * URL and deliberately never exposes the model — it is built to render a dropdown, not to answer
 * questions about a row. Reading fields needs the record itself, so this asks the resources
 * directly. The two are not redundant: one produces the links under the answer, this produces the
 * figures inside it.
 *
 * ## Matched on the folded blob, like everything else here
 *
 * `search_text` is the same index the top bar, the pickers and the assistant's own record tier use,
 * folded on both sides — which is the only reason «شركة» and «شركه» find one tenant.
 */
final class RecordSummary
{
    /** One record per question: the answer is about the thing they named, not a list of maybes. */
    public const MAX_RECORDS = 1;

    /**
     * @param  array<int, string>  $words
     * @return array{title: string, body: string}|null
     */
    public static function find(array $words): ?array
    {
        if ($words === []) {
            return null;
        }

        return rescue(function () use ($words): ?array {
            foreach (Filament::getPanel('admin')->getResources() as $resource) {
                $model = rescue(fn (): string => $resource::getModel(), '', report: false);

                if (! AssistantFields::isSummarisable($model)) {
                    continue;
                }

                if (! rescue(fn (): bool => (bool) $resource::canViewAny(), false, report: false)) {
                    continue;
                }

                $query = $resource::getEloquentQuery();

                foreach ($words as $word) {
                    $query->where('search_text', 'like', '%'.SearchText::normalize($word).'%');
                }

                $record = $query->first();

                if ($record !== null) {
                    return self::describe($record, $model);
                }
            }

            return null;
        }, null, report: false);
    }

    /**
     * @return array{title: string, body: string}
     */
    private static function describe(Model $record, string $model): array
    {
        $spec = AssistantFields::SUMMARISED[$model];
        $lines = [];

        foreach ($spec['columns'] as $column) {
            $value = $record->getAttribute($column);

            if ($value === null || $value === '') {
                continue;
            }

            // Labelled from `admin.fields.*` — the catalogue the FORMS label from — so the
            // assistant names a field the way the screen does, in the reader's language.
            $lines[] = self::label($column).': '.self::stringify($value);
        }

        foreach ($spec['derived'] ?? [] as $label => $method) {
            $value = rescue(fn () => $record->{$method}(), null, report: false);

            if ($value !== null) {
                $lines[] = self::label($label).': '.self::stringify($value);
            }
        }

        return [
            'title' => rescue(fn (): string => (string) ($record->label ?? $record->name ?? $record->number ?? $record->reference ?? class_basename($model)), class_basename($model), report: false),
            'body' => implode("\n", $lines),
        ];
    }

    private static function label(string $key): string
    {
        return trans()->has("admin.fields.{$key}")
            ? (string) __("admin.fields.{$key}")
            : str_replace('_', ' ', $key);
    }

    /**
     * Values are rendered, never re-computed or re-rounded.
     *
     * A money figure quoted to two places when the row holds four is a DIFFERENT NUMBER from the
     * one the screen shows, and the reader has no way to tell which of the two is the balance.
     */
    private static function stringify(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
    }
}
