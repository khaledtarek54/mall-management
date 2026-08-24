<?php

namespace App\Support\Filament;

use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A table group takes its heading from the COLUMN it groups by — never from a second definition.
 *
 * ## What was wrong
 *
 * Filament renders a group heading as *label: title*, and derives BOTH from the raw column when
 * nothing says otherwise: `Group::getLabel()` kebabs the column name into English
 * (`request_type` → "Request type") and `getTitle()` returns the stored value verbatim. So the
 * Arabic panel showed **"Request type: access"** above one group of tenant-request subcategories
 * and **"Type: cause"** on the failure-code register — the label in English, the value in its
 * database spelling, on screens whose every other cell was Arabic.
 *
 * It was not a missing translation. Both tables ALREADY formatted that exact column correctly —
 * `TenantRequestType::label()` on one, `admin.facility.failure_types.*` on the other — and the
 * group simply did not use it. Eight groups across seven resources had the same shape.
 *
 * ## Why it reads the column instead of taking its own resolver
 *
 * The obvious fix is to hand each group a label key and a formatter. That is a SECOND definition of
 * how a value is spelled, next to the column's, free to drift the day somebody edits one of them —
 * and drift here is silent, because a group heading is the one place nobody re-reads. Asking the
 * column means there is exactly one answer to "how is this value written", which is the same rule
 * `Vat::rateForType()` and `ChargeCode::roleFor()` follow for their own questions.
 *
 * Everything is resolved LAZILY, inside closures, so `->defaultGroup(TableGroup::byColumn($table,
 * 'type'))` works whether it is chained before or after `->columns([...])` — both orders exist in
 * this tree.
 *
 * ## When NOT to use it
 *
 * Grouping by a NAME — `tenant.name`, `vendor.name`, `floor.name`. Those headings are operator data
 * and are supposed to read as the operator typed them; "Cilantro" is not an untranslated string.
 * `TableGroupHeadingsAreTranslatedTest` tells the two apart by asking `App\Support\ValueSets`
 * whether the grouped column is a classification, which is a fact the codebase already records
 * rather than a list of exceptions somebody keeps.
 */
final class TableGroup
{
    /**
     * Group by a column, taking the heading's label and its values from that column's own definition.
     */
    public static function byColumn(Table $table, string $column): Group
    {
        return Group::make($column)
            ->label(fn (): string => (string) ($table->getColumn($column)?->getLabel() ?? $column))
            ->getTitleFromRecordUsing(fn (Model $record): ?string => self::titleFor($table, $column, $record))
            ->collapsible();
    }

    /**
     * One row's heading, formatted the way that row's cell would be.
     *
     * The column is CLONED before the record is attached: a table column is a shared, configured
     * object and binding a record to the live one would leave the last-grouped record set on it for
     * every later read. Attaching a record at all is what lets a formatter written as
     * `fn ($record) => …` work here — several are.
     */
    private static function titleFor(Table $table, string $column, Model $record): ?string
    {
        $value = data_get($record, $column);
        $tableColumn = $table->getColumn($column);

        if ($tableColumn === null) {
            return $value === null ? null : (string) $value;
        }

        $formatted = (clone $tableColumn)->record($record)->formatState($value);

        return $formatted === null ? null : (string) $formatted;
    }
}
