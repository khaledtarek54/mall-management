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
            // Required + positive, mirroring the receipt action's identical guard. An item
            // left at cost 0 valued every consumption and write-off of it at 0, so the stock
            // left the warehouse and the journalizer posted nothing — and it priced work-order
            // part draws at 0.00, which asked the approval ladder for its LOWEST tier
            // (gap-analysis F-83). StockMovementService refuses a valueless movement outright;
            // this stops the bad data being created in the first place.
            TextInput::make('unit_cost')
                ->label(__('admin.inventory.fields.unit_cost'))
                ->helperText(__('admin.helpers.inventory_unit_cost'))
                ->numeric()
                ->minValue(0.01)
                ->required()
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
