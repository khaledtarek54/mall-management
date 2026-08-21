<?php

namespace App\Filament\Admin\Resources\VendorDocumentTypes\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rule;

class VendorDocumentTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label(__('admin.fields.code'))
                ->required()
                ->maxLength(40)
                // Immutable: every filed document stores the code itself.
                ->disabledOn('edit')
                ->helperText(__('admin.vendor_document_types_screen.help.code'))
                ->rules([
                    'regex:/^[a-z][a-z0-9_]*$/',
                    fn ($record) => Rule::unique('vendor_document_types', 'code')->ignore($record?->id),
                ]),

            TextInput::make('name_en')->label(__('admin.fields.name_en'))->required()->maxLength(96),
            TextInput::make('name_ar')->label(__('admin.fields.name_ar'))->required()->maxLength(96),

            Toggle::make('blocks_dispatch')
                ->label(__('admin.vendor_document_types_screen.blocks_dispatch'))
                ->default(false)
                ->helperText(__('admin.vendor_document_types_screen.help.blocks_dispatch'))
                ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.vendor_document_blocks_dispatch')),

            TextInput::make('sort_order')
                ->label(__('admin.fields.sort_order'))
                ->numeric()->minValue(0)->default(0)
                ->helperText(__('admin.vendor_document_types_screen.help.sort_order')),

            Toggle::make('is_active')
                ->label(__('admin.fields.is_active'))
                ->default(true)
                ->helperText(__('admin.vendor_document_types_screen.help.is_active')),
        ]);
    }
}
