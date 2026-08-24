<?php

namespace App\Filament\Admin\Resources\Departments\Schemas;

use App\Models\User;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\PropertyField;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DepartmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.resources.department.singular'))
                ->columns(2)
                ->components([
                    // Fixed reference set — identity is seeded, not editable.
                    TextInput::make('name')
                        ->label(__('admin.tables.department.name'))
                        ->required()
                        ->maxLength(150)
                        // Editable on CREATE, frozen afterwards. `Department::booted()` derives the
                        // slug from this name once, and the slug IS the access-role name — so a
                        // rename would leave a department displaying one word while the role that
                        // grants access to it says another. Correct the Arabic name instead, or
                        // retire the department and add the one you meant.
                        ->disabledOn('edit')
                        ->helperText(__('admin.tables.department.name_helper')),

                    TextInput::make('name_ar')
                        ->label(__('admin.fields.name_ar'))
                        ->maxLength(150)
                        // Not required: five departments were seeded before this column existed, and
                        // refusing to save one until somebody types Arabic would block an unrelated
                        // edit. `Department::label()` falls back to the English name.
                        ->helperText(__('admin.tables.department.name_ar_helper')),
                    TextInput::make('code')
                        ->label(__('admin.tables.department.code'))
                        ->disabled()
                        ->dehydrated(false),
                    // A SCOPE control, not a mall picker — see PropertyField::scope(). Department is
                    // the one hybrid model: blank = an operator-wide department every mall shares.
                    PropertyField::scope(allMeans: __('admin.tables.department.global'))
                        ->label(__('admin.tables.department.scope')),
                    EntitySelect::make('head_user_id')
                        ->label(__('admin.tables.department.head'))
                        ->entity(User::class),
                    Toggle::make('is_active')
                        ->label(__('admin.tables.department.active'))
                        ->default(true),
                    TextInput::make('sort_order')
                        ->label(__('admin.tables.department.sort_order'))
                        ->numeric()
                        ->minValue(0)
                        ->default(0),
                    Textarea::make('description')
                        ->label(__('admin.tables.department.description'))
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
