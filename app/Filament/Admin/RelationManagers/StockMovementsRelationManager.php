<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\StockMovement;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Stock movements, shown on whichever record you are looking at — a warehouse or an item.
 *
 * One class, two parents. `$relationship` is `movements` on both `Warehouse` and
 * `InventoryItem`, so the same table answers "what moved in this store?" and "where has this item
 * been?" without a second copy that would drift from the first. Which column is redundant depends on
 * the parent, so the parent's own column is hidden rather than repeated down every row.
 *
 * Read-only. A movement is recorded through `StockMovementService`, which carries the posting-date
 * guard and the GL posting for a receipt or an adjustment; a create button here would be a second
 * way to move stock and value, thinner than the first.
 */
class StockMovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('admin.inventory.movement.plural');
    }

    public function table(Table $table): Table
    {
        $owner = $this->getOwnerRecord();
        $onWarehouse = $owner instanceof \App\Models\Warehouse;

        return $table
            ->columns([
                TextColumn::make('moved_on')
                    ->label(__('admin.inventory.fields.moved_on'))
                    ->date('d/m/Y')
                    ->sortable(),

                // The counterpart record — the item when you are on a warehouse, the warehouse when
                // you are on an item. Repeating the parent down every row says nothing.
                TextColumn::make('item.name')
                    ->label(__('admin.inventory.fields.item'))
                    ->searchable()
                    ->visible($onWarehouse),

                TextColumn::make('warehouse.name')
                    ->label(__('admin.inventory.fields.warehouse'))
                    ->searchable()
                    ->visible(! $onWarehouse),

                TextColumn::make('type')
                    ->label(__('admin.inventory.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.inventory.types.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'receipt' => 'success',
                        'issue' => 'warning',
                        default => 'gray',
                    }),

                // Signed by direction rather than by the stored sign: a receipt of 10 and an issue
                // of 10 are both "10" in the column, and reading a movement list without knowing
                // which way it went is the one thing this table must not allow.
                TextColumn::make('quantity')
                    ->label(__('admin.inventory.fields.quantity'))
                    ->formatStateUsing(fn ($state, StockMovement $record) => ($record->type === 'issue' ? '−' : '+')
                        .number_format((float) $state, 2))
                    ->color(fn (StockMovement $record) => $record->type === 'issue' ? 'warning' : 'success'),

                TextColumn::make('unit_cost')
                    ->label(__('admin.inventory.fields.unit_cost'))
                    ->money('EGP')
                    ->toggleable(),

                TextColumn::make('reference')
                    ->label(__('admin.inventory.fields.reference'))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('movedBy.name')
                    ->label(__('admin.inventory.fields.moved_by'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.inventory.fields.type'))
                    ->options(fn () => __('admin.inventory.types')),
            ])
            // No row action: `StockMovementResource` is index-only, because a movement is an
            // immutable audit row — there is no edit page to open, and the filter-sweep gate caught
            // the attempt to link to one. Everything worth reading is already in the row.
            ->defaultSort('moved_on', 'desc')
            ->emptyStateIcon('heroicon-o-arrows-right-left')
            ->emptyStateHeading(__('admin.empty.stock_movements.heading'))
            ->emptyStateDescription(__('admin.empty.stock_movements.description'));
    }
}
