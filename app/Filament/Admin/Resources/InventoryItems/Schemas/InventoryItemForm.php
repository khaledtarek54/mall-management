<?php

namespace App\Filament\Admin\Resources\InventoryItems\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class InventoryItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('sku')
                ->label(__('admin.inventory.fields.sku'))
                ->required()
                ->maxLength(50)
                ->unique(ignoreRecord: true),
            TextInput::make('name')
                ->label(__('admin.inventory.fields.name'))
                ->required()
                ->maxLength(255),
            TextInput::make('category')
                ->label(__('admin.inventory.fields.category'))
                ->maxLength(255),
            TextInput::make('unit')
                ->label(__('admin.inventory.fields.unit'))
                ->required()
                ->default('each')
                ->maxLength(20)
                ->datalist(['each', 'litre', 'kg', 'metre', 'box', 'roll']),
            TextInput::make('unit_cost')
                ->label(__('admin.inventory.fields.unit_cost'))
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->prefix('EGP'),
            TextInput::make('reorder_level')
                ->label(__('admin.inventory.fields.reorder_level'))
                ->numeric()
                ->minValue(0)
                ->default(0),
            Toggle::make('is_active')
                ->label(__('admin.inventory.fields.active'))
                ->default(true),
        ]);
    }
}
