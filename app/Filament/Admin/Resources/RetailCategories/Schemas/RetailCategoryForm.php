<?php

namespace App\Filament\Admin\Resources\RetailCategories\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class RetailCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label(__('admin.fields.code'))
                ->required()
                ->maxLength(40)
                // Immutable: every tenant row and the public shopper API store the code itself.
                ->disabledOn('edit')
                ->helperText(__('admin.retail_categories_screen.help.code'))
                ->rules([
                    'regex:/^[a-z][a-z0-9_]*$/',
                    fn ($record) => Rule::unique('retail_categories', 'code')->ignore($record?->id),
                ]),

            TextInput::make('name_en')->label(__('admin.fields.name_en'))->required()->maxLength(64),
            TextInput::make('name_ar')->label(__('admin.fields.name_ar'))->required()->maxLength(64),

            TextInput::make('sort_order')
                ->label(__('admin.fields.sort_order'))
                ->numeric()->minValue(0)->default(0)
                ->helperText(__('admin.retail_categories_screen.help.sort_order')),

            Toggle::make('is_active')
                ->label(__('admin.fields.is_active'))
                ->default(true)
                ->helperText(__('admin.retail_categories_screen.help.is_active')),
        ]);
    }
}
