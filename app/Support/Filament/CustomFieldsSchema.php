<?php

namespace App\Support\Filament;

use App\Models\Concerns\HasCustomFields;
use App\Models\CustomField;
use App\Support\CustomFields;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

/**
 * The operator's own fields, rendered into a record's form and onto its record page (D-7 / EG-32).
 *
 * One builder for both, so a field that can be filled in is always a field that can be read back.
 * A form-only implementation is the half-capability this codebase keeps finding — data an operator
 * types and can never see again.
 *
 * ## The section hides itself when there is nothing in it
 *
 * A fresh install defines no fields, and an empty "Additional information" heading on every form is
 * a permanent invitation to a screen that does nothing. `hidden()` is evaluated per render, so the
 * section appears the moment the first definition is saved.
 *
 * ## Values are NOT bound straight to `metadata`
 *
 * Each input is `custom_fields.{key}` — a plain form key with no model attribute behind it — and
 * the page hands the collected array to `HasCustomFields::fillCustomFields()`, which writes only
 * keys the catalogue defines. Binding `metadata.{key}` directly would look tidier and would let a
 * crafted Livewire payload put arbitrary keys into a fillable JSON column, which accepts every one
 * of them without complaint.
 */
final class CustomFieldsSchema
{
    /** The form key the inputs live under — see the class docblock for why it is not `metadata`. */
    public const KEY = 'custom_fields';

    /**
     * A form section carrying every ACTIVE field for this record type.
     *
     * @return array<int, Section>
     */
    public static function form(string $morphAlias): array
    {
        return [
            Section::make(__('admin.custom_fields.section'))
                ->description(__('admin.custom_fields.section_help'))
                ->schema(fn (): array => CustomFields::for($morphAlias)->map(self::input(...))->all())
                ->columns(2)
                ->hidden(fn (): bool => CustomFields::for($morphAlias)->isEmpty()),
        ];
    }

    /**
     * The same fields as read-only entries, including ones since RETIRED.
     *
     * A record page shows what is ON the record. Iterating only the active catalogue would make a
     * value recorded under a deactivated field invisible while it is still stored — which reads as
     * "nothing here" rather than "this was answered under a field we no longer ask".
     *
     * Built from CLOSURES rather than from a record, because an infolist schema is configured once
     * per resource and the record arrives later. Passing a record in would have meant resolving the
     * catalogue at boot, which is the trap that makes a panel argument evaluate exactly once.
     *
     * @return array<int, Section>
     */
    public static function infolist(): array
    {
        return [
            Section::make(__('admin.custom_fields.section'))
                ->schema(fn (?object $record): array => self::entriesFor($record))
                ->columns(2)
                ->hidden(fn (?object $record): bool => self::entriesFor($record) === []),
        ];
    }

    /**
     * The answered fields on a record, as read-only entries.
     *
     * Only what was actually answered: a record page listing twenty empty fields buries the three
     * that were filled in.
     *
     * @return array<int, TextEntry>
     */
    private static function entriesFor(?object $record): array
    {
        if ($record === null || ! method_exists($record, 'customFieldsForDisplay')) {
            return [];
        }

        $values = $record->customFieldValues();

        return $record->customFieldsForDisplay()
            ->filter(fn (CustomField $f): bool => ($values[$f->key] ?? null) !== null)
            ->map(fn (CustomField $f): TextEntry => TextEntry::make(self::KEY.'.'.$f->key)
                ->label($f->label())
                ->state(self::display($f, $values[$f->key] ?? null)))
            ->values()
            ->all();
    }

    /**
     * What a record currently holds, keyed for the form.
     *
     * @param  HasCustomFields  $record
     * @return array<string, mixed>
     */
    public static function fill(object $record): array
    {
        return $record->customFieldValues();
    }

    /** One input, in the shape its type calls for. */
    private static function input(CustomField $field): TextInput|Textarea|DatePicker|Select|Toggle
    {
        $name = self::KEY.'.'.$field->key;
        $label = $field->label();

        $input = match ($field->type) {
            'textarea' => Textarea::make($name)->rows(3)->columnSpanFull(),
            'number' => TextInput::make($name)->numeric(),
            'date' => DatePicker::make($name)->native(false),
            'select' => Select::make($name)->options($field->choices())->native(false),
            // A required toggle is a tick the operator must turn ON, which is a consent box and not
            // a data field — so a boolean is never required, whatever the definition says.
            'boolean' => Toggle::make($name)->inline(false),
            default => TextInput::make($name)->maxLength(255),
        };

        return $input
            ->label($label)
            ->required($field->is_required && $field->type !== 'boolean');
    }

    /** A stored value as the record page should read it. */
    private static function display(CustomField $field, mixed $value): string
    {
        return match ($field->type) {
            'boolean' => $value ? __('admin.custom_fields.yes') : __('admin.custom_fields.no'),
            'select' => $field->choices()[(string) $value] ?? (string) $value,
            default => (string) $value,
        };
    }
}
