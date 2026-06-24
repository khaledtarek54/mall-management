<?php

namespace App\Filament\Admin\Resources\Departments\Schemas;

use Filament\Forms\Components\Select;
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
                    TextInput::make('name')
                        ->label(__('admin.tables.department.name'))
                        ->required()
                        ->maxLength(150),
                    TextInput::make('code')
                        ->label(__('admin.tables.department.code'))
                        ->maxLength(20),
                    Select::make('asset_id')
                        ->label(__('admin.tables.department.scope'))
                        ->relationship('asset', 'name')
                        ->searchable()
                        ->preload()
                        ->placeholder(__('admin.tables.department.global'))
                        ->native(false),
                    Select::make('head_user_id')
                        ->label(__('admin.tables.department.head'))
                        ->relationship('head', 'name')
                        ->searchable()
                        ->preload()
                        ->native(false),
                    Toggle::make('is_active')
                        ->label(__('admin.tables.department.active'))
                        ->default(true),
                    TextInput::make('sort_order')
                        ->label(__('admin.tables.department.sort_order'))
                        ->numeric()
                        ->default(0),
                    Textarea::make('description')
                        ->label(__('admin.tables.department.description'))
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
