<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\Resources\StockMovements\StockMovementResource;
use App\Models\Warehouse;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

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

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.inventory.movement.plural');
    }

    public function table(Table $table): Table
    {
        $owner = $this->getOwnerRecord();
        $onWarehouse = $owner instanceof Warehouse;

        return $table
            // ── Scoped to the property you are in, exactly like the on-hand figure beside it ────
            // `InventoryItem` is `#[PortfolioShared]` (a pump seal is the same part everywhere) and
            // `$relationship` is the bare `movements` hasMany, so on an ITEM parent this table
            // returned EVERY mall's rows. `InventoryItemResource::getEloquentQuery()` narrows both
            // `on_hand` and `stock_value` through `TenantScope::reportAssetIds()` — measured at
            // HEAD, the two halves of one screen answered different questions, and this tab is
            // precisely where an operator goes to explain an on-hand figure they doubt.
            //
            // Routed through the REGISTER's own scoping rather than a `whereIn('warehouse_id', …)`
            // written here: `StockMovement` declares `#[PropertyOwned(via: 'warehouse')]`,
            // `ScopesToProperty` reads that attribute, and this way the tab and
            // `/admin/stock-movements` cannot drift into two answers.
            //
            // Applied on BOTH parents. On a `Warehouse` it is a no-op — the parent is itself
            // property-owned, so its movements are already inside the operator's set — and a branch
            // on the parent type would be a second rule to keep true.
            //
            // The seeded books cannot show this: `mall_management_qa` holds all 17 stock movements
            // in ONE property (AW), which is why the regression test builds the two-mall case.
            ->modifyQueryUsing(fn ($query) => StockMovementResource::scopeToProperty($query))
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
                    ->color('gray'),

                // The QUANTITY carries the direction: `onHand` is `sum('quantity')`, so an outbound
                // movement is stored negative. Read the sign rather than infer it from the type —
                // an `adjustment` goes either way, so a type-based rule would be wrong for the one
                // kind of row an operator is most likely to be querying.
                TextColumn::make('quantity')
                    ->label(__('admin.inventory.fields.quantity'))
                    ->formatStateUsing(fn ($state) => ((float) $state < 0 ? '' : '+').number_format((float) $state, 2))
                    ->color(fn ($state) => (float) $state < 0 ? 'warning' : 'success'),

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
