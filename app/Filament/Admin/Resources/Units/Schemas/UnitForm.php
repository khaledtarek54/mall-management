<?php

namespace App\Filament\Admin\Resources\Units\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UnitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.unit_details'))
                ->columns(3)
                ->components([
                    Select::make('asset_id')
                        ->label(__('admin.tables.unit.asset'))
                        ->relationship('asset', 'name')
                        ->required()
                        ->native(false)
                        ->default(fn () => \App\Support\TenantScope::currentAssetId())
                        ->disabled(fn () => \App\Support\TenantScope::currentAssetId() !== null)
                        ->dehydrated(),
                    TextInput::make('code')
                        ->label(__('admin.tables.unit.code'))
                        ->required()
                        ->maxLength(20)
                        ->placeholder('A-01'),
                    TextInput::make('floor')
                        ->label(__('admin.pdf.floor'))
                        ->maxLength(20),
                    Select::make('category')
                        ->label(__('admin.tables.unit.category'))
                        ->options(fn () => __('admin.enums.category'))
                        ->required()
                        ->native(false),
                    TextInput::make('area_sqm')
                        ->label(__('admin.tables.unit.area'))
                        ->numeric()
                        ->required()
                        ->suffix('m²'),
                    Select::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->options(fn () => __('admin.statuses.unit'))
                        ->required()
                        ->default('vacant')
                        ->native(false),
                    Textarea::make('description')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
