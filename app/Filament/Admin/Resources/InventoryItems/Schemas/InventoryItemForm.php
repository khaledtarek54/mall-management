<?php

namespace App\Filament\Admin\Resources\InventoryItems\Schemas;

use App\Models\InventoryItem;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

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
            // A real dropdown, not a datalist: a <datalist> is only a browser autocomplete
            // hint that shows suggestions while typing and won't reliably open on click, so it
            // read as a broken "dropdown". Built-in suggestions + every unit already in use,
            // plus the field's own current state so a stored-but-unlisted value (legacy free
            // text — this used to be a plain TextInput) or one just added via "create" stays a
            // valid option; otherwise Filament's implicit in:options rule rejects it on save.
            // A "create" affordance keeps the old free-form ability. String column + server-
            // validated Select — no DB-level enum.
            Select::make('unit')
                ->label(__('admin.inventory.fields.unit'))
                ->required()
                ->default('each')
                ->native(false)
                ->searchable()
                ->options(fn (Get $get): array => collect(['each', 'litre', 'kg', 'metre', 'box', 'roll'])
                    ->merge(InventoryItem::query()->pluck('unit'))
                    ->push($get('unit'))
                    ->filter()
                    ->unique()
                    ->mapWithKeys(fn (string $unit): array => [$unit => $unit])
                    ->all())
                ->createOptionForm([
                    TextInput::make('value')
                        ->label(__('admin.inventory.fields.unit'))
                        ->required()
                        ->maxLength(20),
                ])
                ->createOptionUsing(fn (array $data): string => $data['value']),
            // Required + positive, mirroring the receipt action's identical guard. An item
            // left at cost 0 valued every consumption and write-off of it at 0, so the stock
            // left the warehouse and the journalizer posted nothing — and it priced work-order
            // part draws at 0.00, which asked the approval ladder for its LOWEST tier
            // (gap-analysis F-83). StockMovementService refuses a valueless movement outright;
            // this stops the bad data being created in the first place.
            TextInput::make('unit_cost')
                ->label(__('admin.inventory.fields.unit_cost'))
                ->helperText(__('admin.helpers.inventory_unit_cost'))
                ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.inventory_unit_cost'))
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
