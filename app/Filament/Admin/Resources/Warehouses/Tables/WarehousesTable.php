<?php

namespace App\Filament\Admin\Resources\Warehouses\Tables;

use App\Filament\Admin\Resources\Warehouses\WarehouseResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WarehousesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.inventory.fields.name'))
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('code')
                    ->label(__('admin.inventory.fields.code'))
                    ->searchable(),
                TextColumn::make('asset.name')
                    ->label(__('admin.inventory.fields.property'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('category')
                    ->label(__('admin.inventory.fields.category'))
                    ->placeholder('—'),
                IconColumn::make('is_active')
                    ->label(__('admin.inventory.fields.active'))
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make()->visible(fn ($record) => WarehouseResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->visible(fn () => WarehouseResource::canDeleteAny()),
                ]),
            ])
            ->defaultSort('name');
    }
}
