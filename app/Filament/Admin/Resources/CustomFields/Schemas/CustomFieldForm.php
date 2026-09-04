<?php

namespace App\Filament\Admin\Resources\CustomFields\Schemas;

use App\Models\CustomField;
use App\Support\CustomFields;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * Defining a field the operator's records will carry (D-7 / EG-32).
 *
 * Two fields are disabled once the row exists, and both for the same reason: they are the ADDRESS
 * of every value already recorded. `model` says which table's `metadata` holds them and `key` says
 * under which JSON key, so changing either strands the data — it stays on the records and nothing
 * can read it again. `CustomField::saving()` refuses the change too; this is the UI half, and the
 * model is the gate.
 *
 * The LABEL is what an operator renames, in both languages, and it reaches every record at once
 * because a label is resolved at read time.
 */
class CustomFieldForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('model')
                    ->label(__('admin.custom_fields.model'))
                    ->options(fn (): array => collect(array_keys(CustomFields::EXTENSIBLE))
                        ->mapWithKeys(fn (string $alias): array => [$alias => __("admin.custom_fields.models.{$alias}")])
                        ->all())
                    ->required()
                    ->native(false)
                    // The address of every value already recorded — see the class docblock.
                    ->disabled(fn (?CustomField $record): bool => $record !== null)
                    ->dehydrated()
                    ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.custom_fields.help.model')),

                TextInput::make('key')
                    ->label(__('admin.custom_fields.key'))
                    ->required()
                    ->maxLength(64)
                    // A JSON key that is also safe as a form-field path: Filament reads a dot as
                    // nesting, so `parent.group` would silently become a two-level array.
                    ->rule('regex:/^[a-z][a-z0-9_]*$/')
                    // Unique PER RECORD TYPE, which the table has enforced since it was created and
                    // nothing above it asked — so a second field on a key this record type already
                    // uses came back as a raw duplicate-key 500 (SW-118). Refused INLINE rather
                    // than as a thrown refusal, because a `DomainException` redirects back and
                    // loses everything else the operator typed; `CustomField::saving()` is the gate
                    // an import or a crafted payload meets, and both read `keyConflictRefusal()`
                    // so the field error and the toast cannot word it differently.
                    ->rule(static fn ($get, ?CustomField $record): Closure => static function (string $attribute, $value, Closure $fail) use ($get, $record): void {
                        $conflict = CustomField::keyConflictRefusal((string) $get('model'), (string) $value, $record?->getKey());

                        if ($conflict !== null) {
                            $fail(__($conflict['key'], $conflict['replace']));
                        }
                    })
                    ->helperText(__('admin.custom_fields.help.key'))
                    ->disabled(fn (?CustomField $record): bool => $record !== null)
                    ->dehydrated()
                    // Typed once, from the English label, and never re-derived: after the first
                    // keystroke the operator owns it, and a rename must not move the address.
                    ->default(null),

                TextInput::make('label_en')
                    ->label(__('admin.custom_fields.label_en'))
                    ->required()
                    ->maxLength(96)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Get $get, callable $set): void {
                        if ($get('key') === null || $get('key') === '') {
                            $set('key', Str::of((string) $state)->snake()->replaceMatches('/[^a-z0-9_]/', '')->toString());
                        }
                    }),

                TextInput::make('label_ar')
                    ->label(__('admin.custom_fields.label_ar'))
                    ->required()
                    ->maxLength(96),

                Select::make('type')
                    ->label(__('admin.custom_fields.type'))
                    ->options(fn (): array => collect(CustomField::TYPES)
                        ->mapWithKeys(fn (string $t): array => [$t => __("admin.custom_fields.types.{$t}")])
                        ->all())
                    ->required()
                    ->native(false)
                    ->live(),

                Toggle::make('is_required')
                    ->label(__('admin.custom_fields.is_required'))
                    ->helperText(__('admin.custom_fields.help.is_required'))
                    // A required tick is a consent box, not a data field — `CustomFieldsSchema`
                    // never marks a boolean required, so offering it here would be a promise the
                    // form does not keep.
                    ->visible(fn (Get $get): bool => $get('type') !== 'boolean'),

                Repeater::make('options')
                    ->label(__('admin.custom_fields.options'))
                    ->addActionLabel(__('admin.custom_fields.add_option'))
                    ->schema([
                        TextInput::make('value')
                            ->label(__('admin.custom_fields.option_value'))
                            ->required()
                            ->maxLength(64),
                        TextInput::make('label_en')->label(__('admin.custom_fields.label_en'))->required()->maxLength(96),
                        TextInput::make('label_ar')->label(__('admin.custom_fields.label_ar'))->required()->maxLength(96),
                    ])
                    ->columns(3)
                    ->columnSpanFull()
                    ->helperText(__('admin.custom_fields.help.options'))
                    ->visible(fn (Get $get): bool => $get('type') === 'select')
                    ->minItems(1),

                TextInput::make('sort_order')
                    ->label(__('admin.fields.sort_order'))
                    ->numeric()
                    ->default(0)
                    ->helperText(__('admin.custom_fields.help.sort_order')),

                Toggle::make('is_active')
                    ->label(__('admin.fields.is_active'))
                    ->default(true)
                    ->helperText(__('admin.custom_fields.help.is_active')),
            ]);
    }
}
