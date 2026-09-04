<?php

namespace App\Filament\Admin\Resources\DocumentTemplates\Schemas;

use App\Support\DocumentText;
use App\Support\Filament\PropertyField;
use App\Support\TenantScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rule;

class DocumentTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.document_templates_screen.sections.which'))
                ->columns(2)
                ->components([
                    Select::make('key')
                        ->label(__('admin.fields.document_block'))
                        ->required()
                        // Offered from the registry, so a template cannot be written for a slot no
                        // document renders. `ValueSets` refuses one at the model layer too — the
                        // picker narrows what you SEE, the guard is what makes it true.
                        ->options(fn (): array => collect(DocumentText::KEY_NAMES)
                            ->mapWithKeys(fn (string $k): array => [$k => __('admin.document_templates_screen.blocks.'.str_replace('.', '_', $k))])
                            ->all())
                        // Immutable: the pair (block, property) is the row's identity, and moving a
                        // written block to a different slot is adding one and retiring the other.
                        ->disabledOn('edit')
                        // One row per block per property, matching the unique index — refused here
                        // so the operator gets a field error instead of a duplicate-key 500.
                        //
                        // **The scope is CLAMPED, never read raw.** This screen's property control
                        // is `PropertyField::scope()`, a Radio whose blank state is the STRING `''`
                        // and not null, so `$get('asset_id')` handed the rule an empty string for
                        // the HOUSE row. Measured 2026-09-04 on the dev database: it compiled to
                        // `where "key" = ? and ("asset_id" = ?)` bound to `""`, and `asset_id = ''`
                        // can never match a NULL row — so the check never fired for the one scope
                        // where the index cannot help either (MySQL permits unlimited duplicates on
                        // a nullable unique column). `clampAssetId('')` is null, which Laravel
                        // compiles to `is null`; it is also what `HolidayForm` — the same control
                        // asking the same question — has always done.
                        ->rules([
                            fn ($record, $get) => Rule::unique('document_templates', 'key')
                                ->where(fn ($q) => $q->where('asset_id', TenantScope::clampAssetId($get('asset_id'))))
                                ->ignore($record?->id),
                        ])
                        ->helperText(__('admin.document_templates_screen.help.key')),

                    // A SCOPE control, not a mall picker — see PropertyField::scope(). This screen is
                    // portfolio configuration: the null-property row IS the house default, and the
                    // second option is how you override it for the mall you are standing in.
                    PropertyField::scope(allMeans: __('admin.document_templates_screen.house_default'))
                        ->helperText(__('admin.document_templates_screen.help.asset')),
                ]),

            Section::make(__('admin.document_templates_screen.sections.wording'))
                ->description(__('admin.document_templates_screen.sections.wording_description'))
                ->components([
                    Textarea::make('body_en')
                        ->label(__('admin.fields.body_en'))
                        ->rows(4)
                        ->maxLength(2000)
                        ->helperText(__('admin.document_templates_screen.help.body')),

                    Textarea::make('body_ar')
                        ->label(__('admin.fields.body_ar'))
                        ->rows(4)
                        ->maxLength(2000)
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.document_template_locale')),

                    Toggle::make('is_active')
                        ->label(__('admin.fields.is_active'))
                        ->default(true)
                        ->helperText(__('admin.document_templates_screen.help.is_active')),
                ]),
        ]);
    }
}
