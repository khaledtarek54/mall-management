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
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('code')
                        ->label(__('admin.tables.department.code'))
                        ->disabled()
                        ->dehydrated(false),
                    // FREE by design — see PropertyField::PORTFOLIO_LEVEL. Department is the one
                    // hybrid model: blank = an operator-wide department every mall shares.
                    PropertyField::free(blankMeans: __('admin.tables.department.global'))
                        ->label(__('admin.tables.department.scope'))
                        ->preload(),
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
