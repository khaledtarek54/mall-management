<?php

namespace App\Filament\Admin\Resources\InventoryItems\Tables;

use App\Filament\Admin\Resources\InventoryItems\InventoryItemResource;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class InventoryItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')
                    ->label(__('admin.inventory.fields.sku'))
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('admin.inventory.fields.name'))
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->label(__('admin.inventory.fields.category'))
                    ->sortable()
                    ->placeholder('—'),
                // `on_hand` and `stock_value` are withSum() aliases from
                // InventoryItemResource::getEloquentQuery(), so ORDER BY resolves them.
                // Sorting on_hand ascending IS the reorder worklist — the single most
                // useful ordering this table has, and it was unavailable.
                TextColumn::make('on_hand')
                    ->label(__('admin.inventory.fields.on_hand'))
                    ->numeric(decimalPlaces: 3)
                    ->default(0)
                    // Highlight when at/below the reorder level (low stock).
                    ->color(fn ($state, $record) => (float) $state <= (float) $record->reorder_level ? 'danger' : 'success')
                    ->weight('bold')
                    ->sortable()
                    ->suffix(fn ($record) => ' '.$record->unit),
                TextColumn::make('unit_cost')
                    ->label(__('admin.inventory.fields.unit_cost'))
                    ->money('EGP')
                    ->sortable()
                    ->toggleable(),
                // What the stock on hand is WORTH (on_hand × unit cost) — the number an operator
                // and their accountant actually want, and which was nowhere on screen.
                TextColumn::make('stock_value')
                    ->label(__('admin.inventory.fields.value'))
                    ->state(fn ($record) => round((float) ($record->stock_value ?? 0), 2))
                    ->money('EGP')
                    ->alignRight()
                    ->weight('bold')
                    ->sortable()
                    // The value of the whole (filtered) catalogue — the number the accountant
                    // reconciles the inventory account against. Summed in SQL because the
                    // column itself is derived state, not a real column.
                    ->summarize(
                        Summarizer::make('total')
                            ->label(__('admin.inventory.fields.total_value'))
                            ->money('EGP')
                            ->using(fn (Builder $query): float => (float) $query->sum(DB::raw('stock_value')))
                    ),
                TextColumn::make('reorder_level')
                    ->label(__('admin.inventory.fields.reorder_level'))
                    ->numeric(decimalPlaces: 3)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label(__('admin.inventory.fields.active'))
                    ->boolean(),
            ])
            ->filters([
                // The reorder worklist. The on_hand column already turns red at/below the
                // reorder level; this makes that state selectable instead of eyeballable.
                //
                // `on_hand` is the resource's withSum alias, not a column, so this can be
                // neither whereColumn (can't see a select alias) nor HAVING (without a
                // GROUP BY, HAVING collapses the result to ONE group and stops filtering
                // per row — it silently returned every item). A correlated subquery is
                // the honest form, and it repeats the resource's property scoping so the
                // filter reads the SAME figure the column shows: stock in THIS mall's
                // warehouses, never another mall's shelf.
                Filter::make('low_stock')
                    ->label(__('admin.inventory.filters.low_stock'))
                    ->query(function ($query) {
                        $assetIds = InventoryItemResource::scopedAssetIds();

                        return $query->where(
                            'inventory_items.reorder_level',
                            '>=',
                            StockMovement::query()
                                ->selectRaw('coalesce(sum(quantity), 0)')
                                ->whereColumn('inventory_item_id', 'inventory_items.id')
                                ->when($assetIds !== null, fn ($q) => $q->whereIn(
                                    'warehouse_id',
                                    Warehouse::query()->whereIn('asset_id', $assetIds)->select('id'),
                                ))
                        );
                    }),
                TernaryFilter::make('is_active')
                    ->label(__('admin.inventory.fields.active')),
                SelectFilter::make('category')
                    ->label(__('admin.inventory.fields.category'))
                    ->options(fn (): array => InventoryItem::query()
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
                    ->visible(fn ($record) => InventoryItemResource::canView($record))
                    ->authorize(fn ($record) => InventoryItemResource::canView($record)),
                EditAction::make()->visible(fn ($record) => InventoryItemResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->visible(fn () => InventoryItemResource::canDeleteAny()),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-cube')
            ->emptyStateHeading(__('admin.empty.inventory_items.heading'))
            ->emptyStateDescription(__('admin.empty.inventory_items.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.inventory_items.cta'))
                    ->icon('heroicon-o-plus'),
            ])
            ->defaultSort('name');
    }
}
