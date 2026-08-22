<?php

namespace App\Filament\Admin\Resources\DocumentTemplates\Schemas;

use App\Support\DocumentText;
use App\Support\Filament\PropertyField;
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
                        ->rules([
                            fn ($record, $get) => Rule::unique('document_templates', 'key')
                                ->where(fn ($q) => $q->where('asset_id', $get('asset_id')))
                                ->ignore($record?->id),
                        ])
                        ->helperText(__('admin.document_templates_screen.help.key')),

                    // Free, because this screen is portfolio configuration: the null-property row IS
                    // the house default, and picking a mall is how you override it for that mall.
                    PropertyField::free(blankMeans: __('admin.document_templates_screen.house_default'))
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
