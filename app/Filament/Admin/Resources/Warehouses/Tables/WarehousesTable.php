<?php

namespace App\Filament\Admin\Resources\Warehouses\Tables;

use App\Filament\Admin\Resources\Warehouses\WarehouseResource;
use App\Models\Warehouse;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
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
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('admin.inventory.fields.active')),
                // Category is free-text on the form (with a "create" affordance), so the
                // filter offers whatever is actually in use rather than a fixed list.
                SelectFilter::make('category')
                    ->label(__('admin.inventory.fields.category'))
                    ->options(fn (): array => Warehouse::query()
                        ->whereNotNull('category')
                        ->distinct()
                        ->orderBy('category')
                        ->pluck('category', 'category')
                        ->all()),
                TrashedFilter::make(),
            ])
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => WarehouseResource::canView($record))
                    ->authorize(fn ($record) => WarehouseResource::canView($record)),
                EditAction::make()->visible(fn ($record) => WarehouseResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->visible(fn () => WarehouseResource::canDeleteAny()),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-building-storefront')
            ->emptyStateHeading(__('admin.empty.warehouses.heading'))
            ->emptyStateDescription(__('admin.empty.warehouses.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.warehouses.cta'))
                    ->icon('heroicon-o-plus'),
            ])
            ->defaultSort('name');
    }
}
