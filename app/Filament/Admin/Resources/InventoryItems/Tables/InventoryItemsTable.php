<?php

namespace App\Filament\Admin\Resources\InventoryItems\Tables;

use App\Filament\Admin\Resources\InventoryItems\InventoryItemResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InventoryItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')
                    ->label(__('admin.inventory.fields.sku'))
                    ->fontFamily('mono')
                    ->searchable(),
                TextColumn::make('name')
                    ->label(__('admin.inventory.fields.name'))
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('category')
                    ->label(__('admin.inventory.fields.category'))
                    ->placeholder('—'),
                TextColumn::make('on_hand')
                    ->label(__('admin.inventory.fields.on_hand'))
                    ->numeric(decimalPlaces: 3)
                    ->default(0)
                    // Highlight when at/below the reorder level (low stock).
                    ->color(fn ($state, $record) => (float) $state <= (float) $record->reorder_level ? 'danger' : 'success')
                    ->weight('bold')
                    ->suffix(fn ($record) => ' ' . $record->unit),
                TextColumn::make('unit_cost')
                    ->label(__('admin.inventory.fields.unit_cost'))
                    ->money('EGP')
                    ->toggleable(),
                TextColumn::make('reorder_level')
                    ->label(__('admin.inventory.fields.reorder_level'))
                    ->numeric(decimalPlaces: 3)
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label(__('admin.inventory.fields.active'))
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make()->visible(fn ($record) => InventoryItemResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->visible(fn () => InventoryItemResource::canDeleteAny()),
                ]),
            ])
            ->defaultSort('name');
    }
}
