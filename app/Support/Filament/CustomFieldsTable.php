<?php

namespace App\Support\Filament;

use App\Models\CustomField;
use App\Support\CustomFields;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Imports\ImportColumn;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * The operator's own fields, as LIST columns, FILTERS and EXPORT columns (D-7 / EG-32 slice 3).
 *
 * Slice 2 made a custom field fillable and readable on its record. That is half a capability: an
 * operator who records a parent buying group on two hundred tenants wants a list BY parent group,
 * and a spreadsheet of it — which is the whole reason they asked for the field. A value you can
 * type and never group by is the notes box with extra steps.
 *
 * ## The value is read two different ways, on purpose
 *
 * **Display** goes through the model: `custom_fields.{key}` resolves against the virtual accessor,
 * so a column shows exactly what the record page shows, formatted by the same rules.
 *
 * **Query** goes through SQL: `metadata->{key}` is a JSON path Laravel compiles per driver
 * (`json_extract` on SQLite, `json_contains_path`/`json_unquote` on MySQL). Sorting and filtering
 * must happen in the database — a collection filter would page wrongly and a sort would only order
 * the rows already fetched.
 *
 * A record with no answer has `NULL` at that path, so every comparison excludes it. That is the
 * right default: "no parent group recorded" is not "parent group is empty".
 *
 * ## Columns ship HIDDEN
 *
 * `toggleable(isToggledHiddenByDefault: true)`. An operator who defines eight fields must not find
 * eight new columns on a list they were happy with. They turn on the ones they want — and because
 * of EG-32 slice 1 they can save that choice as a view and hand it to a colleague.
 */
final class CustomFieldsTable
{
    /**
     * A column per active field, hidden until asked for.
     *
     * @return array<int, TextColumn|IconColumn>
     */
    public static function columns(string $morphAlias): array
    {
        return CustomFields::for($morphAlias)->map(function (CustomField $field) {
            $name = CustomFieldsSchema::KEY.'.'.$field->key;

            // A tick reads faster than the word "Yes" in a dense list, and matches how every other
            // boolean column in this panel renders.
            if ($field->type === 'boolean') {
                return IconColumn::make($name)
                    ->label($field->label())
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true);
            }

            return TextColumn::make($name)
                ->label($field->label())
                ->formatStateUsing(fn ($state): string => $field->type === 'select'
                    ? ($field->choices()[(string) $state] ?? (string) $state)
                    : (string) $state)
                ->placeholder('—')
                // Sorted in the DATABASE. Filament cannot infer a sort for a name that is not a
                // column, so the query is stated: without it the header would sort nothing and
                // look broken.
                ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                    ->orderBy('metadata->'.$field->key, $direction))
                ->toggleable(isToggledHiddenByDefault: true);
        })->all();
    }

    /**
     * A filter per active field, in the shape its type calls for.
     *
     * Named `cf_{key}` rather than by the bare key: a filter name is a query-string key and shares
     * that namespace with every other filter on the table, so an operator field called `status`
     * would otherwise collide with the resource's own status filter and silently take it over.
     *
     * **The builder parameter must be named `$query`.** Filament resolves a closure's arguments by
     * PARAMETER NAME, so a filter written with `$q` registers, renders, and filters nothing — it
     * looks completely correct and the list simply ignores it.
     *
     * @return array<int, Filter|TernaryFilter>
     */
    public static function filters(string $morphAlias): array
    {
        return CustomFields::for($morphAlias)->map(function (CustomField $field) {
            $name = 'cf_'.$field->key;
            $path = 'metadata->'.$field->key;

            return match ($field->type) {
                'boolean' => TernaryFilter::make($name)
                    ->label($field->label())
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where($path, true),
                        false: fn (Builder $query): Builder => $query->where($path, false),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                'select' => Filter::make($name)
                    // The FILTER's own label, not only the Select's inside it. Without it Filament
                    // derives one from the name — and the name is `cf_{key}`, so the Arabic panel
                    // offered a filter called "Cf parent group". Three of the five branches below
                    // set it and two did not; the two that did not are the two an operator sees
                    // most, because text and choice are what a custom field usually is.
                    ->label($field->label())
                    ->schema([
                        Select::make('value')
                            ->label($field->label())
                            ->options($field->choices())
                            ->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['value'] ?? null), fn (Builder $inner): Builder => $inner->where($path, $data['value'])))
                    ->indicateUsing(fn (array $data): ?string => filled($data['value'] ?? null)
                        ? $field->label().': '.($field->choices()[(string) $data['value']] ?? $data['value'])
                        : null),

                'date' => Filter::make($name)
                    ->label($field->label())
                    ->schema([
                        DatePicker::make('from')->label(__('admin.filters.date_from'))->native(false),
                        DatePicker::make('until')->label(__('admin.filters.date_until'))->native(false),
                    ])
                    // A date is stored as a plain `Y-m-d` string inside JSON, which compares and
                    // sorts correctly as text — so this is a string comparison on purpose. Casting
                    // per row would defeat any index and cannot be pushed into the JSON path.
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['from'] ?? null), fn (Builder $inner): Builder => $inner->where($path, '>=', $data['from']))
                        ->when(filled($data['until'] ?? null), fn (Builder $inner): Builder => $inner->where($path, '<=', $data['until'])))
                    ->indicateUsing(fn (array $data): ?string => filled($data['from'] ?? null) || filled($data['until'] ?? null)
                        ? $field->label().': '.($data['from'] ?? '…').' → '.($data['until'] ?? '…')
                        : null),

                'number' => Filter::make($name)
                    ->label($field->label())
                    ->schema([
                        TextInput::make('min')->label(__('admin.custom_fields.min'))->numeric(),
                        TextInput::make('max')->label(__('admin.custom_fields.max'))->numeric(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['min'] ?? null), fn (Builder $inner): Builder => $inner->where($path, '>=', (float) $data['min']))
                        ->when(filled($data['max'] ?? null), fn (Builder $inner): Builder => $inner->where($path, '<=', (float) $data['max'])))
                    ->indicateUsing(fn (array $data): ?string => filled($data['min'] ?? null) || filled($data['max'] ?? null)
                        ? $field->label().': '.($data['min'] ?? '…').' – '.($data['max'] ?? '…')
                        : null),

                // text · textarea — CONTAINS, because an operator looking for "Americana" should not
                // have to remember whether they typed "Americana Group".
                default => Filter::make($name)
                    ->label($field->label())
                    ->schema([
                        TextInput::make('value')->label($field->label()),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['value'] ?? null), fn (Builder $inner): Builder => $inner->where($path, 'like', '%'.$data['value'].'%')))
                    ->indicateUsing(fn (array $data): ?string => filled($data['value'] ?? null)
                        ? $field->label().': '.$data['value']
                        : null),
            };
        })->all();
    }

    /**
     * An IMPORT column per active field.
     *
     * The last direction the operator's own data could not travel: a migrating operator keeps their
     * columns in the spreadsheet they are already importing records from, and without this they
     * would have to key every answer in by hand after the import.
     *
     * **Filled through `fillRecordUsing()`, never by attribute name.** Filament's default fill does
     * `data_set($record, $name, $state)`, which for a dotted name would build a nested array on the
     * model and for a bare one would set an attribute that is not a column. Routing through
     * `fillCustomFields()` means an import gets exactly the same key filtering and type casting a
     * form does — a CSV cannot introduce a key the catalogue never defined.
     *
     * A `select` is imported by its STORED VALUE, matching what the export writes, so a sheet
     * exported from here re-imports cleanly.
     *
     * @return array<int, ImportColumn>
     */
    public static function importColumns(string $morphAlias): array
    {
        return CustomFields::for($morphAlias)
            ->map(fn (CustomField $field): ImportColumn => ImportColumn::make('cf_'.$field->key)
                ->label($field->label())
                ->fillRecordUsing(function ($record, $state) use ($field): void {
                    $record->fillCustomFields([$field->key => $state]);
                }))
            ->all();
    }

    /**
     * An export column per active field, at the END of the sheet.
     *
     * Last on purpose: the shipped columns are what another system joins on, and an operator who
     * adds a field should not silently move the column positions a colleague's import template
     * depends on.
     *
     * A `select` exports its STORED VALUE, not its label — an export is read by another system, and
     * the value is the stable half of the pair. The label is on screen, where a person reads it.
     *
     * @return array<int, ExportColumn>
     */
    public static function exportColumns(string $morphAlias): array
    {
        return CustomFields::for($morphAlias)
            ->map(fn (CustomField $field): ExportColumn => ExportColumn::make(CustomFieldsSchema::KEY.'.'.$field->key)
                ->label($field->label()))
            ->all();
    }
}
