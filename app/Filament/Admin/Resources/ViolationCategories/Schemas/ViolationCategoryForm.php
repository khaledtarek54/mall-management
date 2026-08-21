<?php

namespace App\Filament\Admin\Resources\ViolationCategories\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class ViolationCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label(__('admin.fields.code'))
                ->required()
                ->maxLength(40)
                // Immutable: every violation row stores the code itself.
                ->disabledOn('edit')
                ->helperText(__('admin.violation_categories_screen.help.code'))
                ->rules([
                    'regex:/^[a-z][a-z0-9_]*$/',
                    fn ($record) => Rule::unique('violation_categories', 'code')->ignore($record?->id),
                ]),

            TextInput::make('name_en')->label(__('admin.fields.name_en'))->required()->maxLength(96),
            TextInput::make('name_ar')->label(__('admin.fields.name_ar'))->required()->maxLength(96),

            TextInput::make('default_fine_amount')
                // `admin.fields.*` — the same word the activity log uses for this column.
                ->label(__('admin.fields.default_fine_amount'))
                ->numeric()
                ->minValue(0)
                ->prefix(config('app.currency', 'EGP'))
                ->helperText(__('admin.violation_categories_screen.help.default_fine')),

            TextInput::make('sort_order')
                ->label(__('admin.fields.sort_order'))
                ->numeric()->minValue(0)->default(0)
                ->helperText(__('admin.violation_categories_screen.help.sort_order')),

            Toggle::make('is_active')
                ->label(__('admin.fields.is_active'))
                ->default(true)
                ->helperText(__('admin.violation_categories_screen.help.is_active')),
        ]);
    }
}
