<?php

namespace App\Filament\Admin\Resources\StockMovements\Tables;

use App\Models\StockMovement;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class StockMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('moved_on')
                    ->label(__('admin.inventory.fields.moved_on'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('admin.inventory.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.inventory.types.{$state}"))
                    ->color(fn (string $state) => in_array($state, StockMovement::ADDS_STOCK, true) ? 'success' : (in_array($state, StockMovement::REMOVES_STOCK, true) ? 'danger' : 'gray')),
                TextColumn::make('item.name')
                    ->label(__('admin.inventory.fields.item'))
                    ->description(fn ($record) => $record->item?->sku)
                    ->searchable(),
                TextColumn::make('warehouse.name')
                    ->label(__('admin.inventory.fields.warehouse'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('quantity')
                    ->label(__('admin.inventory.fields.quantity'))
                    ->numeric(decimalPlaces: 3)
                    ->weight('bold')
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : 'success'),
                TextColumn::make('unit_cost')
                    ->label(__('admin.inventory.fields.unit_cost'))
                    ->money('EGP')
                    ->toggleable(),
                TextColumn::make('reference')
                    ->label(__('admin.inventory.fields.reference'))
                    ->limit(24)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('movedBy.name')
                    ->label(__('admin.inventory.fields.moved_by'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.inventory.fields.type'))
                    ->options(fn () => collect(StockMovement::TYPES)->mapWithKeys(fn ($t) => [$t => __("admin.inventory.types.{$t}")])->all()),
            ])
            ->defaultSort('moved_on', 'desc');
    }
}
