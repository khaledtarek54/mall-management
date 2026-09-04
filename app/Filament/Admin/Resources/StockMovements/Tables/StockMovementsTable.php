<?php

namespace App\Filament\Admin\Resources\StockMovements\Tables;

use App\Filament\Actions\LedgerEntryAction;
use App\Filament\Admin\Resources\StockMovements\StockMovementResource;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\Filament\EntitySelectFilter;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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
                // Blank for the operators who do not rack their storeroom — which is most of the
                // point: the column costs them nothing.
                TextColumn::make('bin.code')
                    ->label(__('admin.inventory.fields.bin'))
                    ->placeholder('—')
                    ->fontFamily('mono')
                    ->size('xs')
                    ->toggleable(isToggledHiddenByDefault: true),
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
            // **An append-only register you cannot narrow is one you cannot audit** (SW-198).
            // This carried ONE filter — movement type — which the tabs above it already offer, so
            // "what left the Consumables store in March", the question asked at every stock count
            // and the only way to explain a variance, meant scrolling a table that grows for ever.
            // Every other dated register in the panel (custodies, payments, expenses) has carried
            // a date range for months.
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.inventory.fields.type'))
                    ->options(fn () => collect(StockMovement::TYPES)->mapWithKeys(fn ($t) => [$t => __("admin.inventory.types.{$t}")])->all()),
                // Record filters, so the dropdown and its chip read exactly like the pickers on
                // the Receive / Adjust / Transfer modals. Deliberately NOT narrowed to active
                // rows: a ledger has to be filterable by the store or the item that was retired,
                // which is precisely when somebody comes looking.
                EntitySelectFilter::make('warehouse_id')
                    ->label(__('admin.inventory.fields.warehouse'))
                    ->relationship('warehouse')
                    ->entity(Warehouse::class),
                EntitySelectFilter::make('inventory_item_id')
                    ->label(__('admin.inventory.fields.item'))
                    ->relationship('item')
                    ->entity(InventoryItem::class),
                Filter::make('moved_on')
                    ->label(__('admin.inventory.fields.moved_on'))
                    ->schema([
                        DatePicker::make('from')->label(__('admin.filters.date_from'))->native(false),
                        DatePicker::make('until')->label(__('admin.filters.date_until'))->native(false),
                    ])
                    // `$query`, never `$q`: Filament resolves an untyped closure argument by NAME,
                    // and a filter whose first parameter is named anything else registers,
                    // renders and filters nothing while looking correct in review.
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($scoped, $date) => $scoped->whereDate('moved_on', '>=', $date))
                        ->when($data['until'] ?? null, fn ($scoped, $date) => $scoped->whereDate('moved_on', '<=', $date))),
            ])
            // The only register in the panel with no way to open a row. Three of its columns
            // (unit cost, source reference, who moved it) are toggled off by default and `notes`
            // was on no screen at all, so "why did 40 units leave on the 3rd?" was unanswerable
            // without the database. Append-only, so this is a read-only modal, not an edit page —
            // native infolist entries per the house pattern rather than a custom Blade view.
            ->recordActions([
                // **What this document did to the books, from the document.** CHANGE-IMPACT-PLAN
                // §6.1 built the panel and mounted it on five tables; D4 extended it to the Edit
                // headers. These six sources have an operator screen and had neither — so the one
                // question a derived ledger makes people ask ("what happened to my entry?") had no
                // answer here. Read-only and gated on `general_ledger.view`.
                LedgerEntryAction::make(),
                Action::make('details')
                    ->label(__('admin.actions.view'))
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (StockMovement $record) => __('admin.inventory.movement.singular').' — '.$record->item?->name)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('admin.actions.close'))
                    ->visible(fn () => StockMovementResource::canViewAny())
                    ->authorize(fn () => StockMovementResource::canViewAny())
                    ->schema(fn (StockMovement $record) => self::detailsSchema($record)),
            ])
            ->defaultSort('moved_on', 'desc')
            ->emptyStateIcon('heroicon-o-arrows-right-left')
            ->emptyStateHeading(__('admin.empty.stock_movements.heading'))
            ->emptyStateDescription(__('admin.empty.stock_movements.description'));
    }

    /**
     * The full movement, including the columns the table hides by default.
     *
     * @return array<int, TextEntry>
     */
    public static function detailsSchema(StockMovement $record): array
    {
        $quantity = (float) $record->quantity;
        $unitCost = $record->unit_cost === null ? null : (float) $record->unit_cost;

        return array_values(array_filter([
            TextEntry::make('d_type')->label(__('admin.inventory.fields.type'))->inlineLabel()
                ->state(__("admin.inventory.types.{$record->type}"))
                ->badge()
                ->color(in_array($record->type, StockMovement::ADDS_STOCK, true)
                    ? 'success'
                    : (in_array($record->type, StockMovement::REMOVES_STOCK, true) ? 'danger' : 'gray')),
            TextEntry::make('d_item')->label(__('admin.inventory.fields.item'))->inlineLabel()
                ->state(trim(($record->item?->name ?? '').' ('.($record->item?->sku ?? '').')', ' ()')),
            TextEntry::make('d_warehouse')->label(__('admin.inventory.fields.warehouse'))->inlineLabel()
                ->state($record->warehouse?->name),
            TextEntry::make('d_quantity')->label(__('admin.inventory.fields.quantity'))->inlineLabel()
                ->state(number_format($quantity, 3))
                ->weight(FontWeight::Bold)
                ->color($quantity < 0 ? 'danger' : 'success'),
            TextEntry::make('d_moved_on')->label(__('admin.inventory.fields.moved_on'))->inlineLabel()
                ->state($record->moved_on?->format('d/m/Y')),
            $unitCost === null ? null : TextEntry::make('d_unit_cost')->label(__('admin.inventory.fields.unit_cost'))->inlineLabel()
                ->state('EGP '.number_format($unitCost, 2)),
            // The line's money value — quantity x unit cost. It is what the movement did to the
            // inventory account, and it appeared nowhere on any screen.
            $unitCost === null ? null : TextEntry::make('d_value')->label(__('admin.inventory.fields.value'))->inlineLabel()
                ->state('EGP '.number_format(abs($quantity) * $unitCost, 2))
                ->weight(FontWeight::Bold),
            TextEntry::make('d_reference')->label(__('admin.inventory.fields.reference'))->inlineLabel()
                ->state($record->reference)->placeholder('—'),
            TextEntry::make('d_moved_by')->label(__('admin.inventory.fields.moved_by'))->inlineLabel()
                ->state($record->movedBy?->name)->placeholder('—'),
            TextEntry::make('d_notes')->label(__('admin.inventory.fields.notes'))->inlineLabel()
                ->state($record->notes)->placeholder('—'),
        ]));
    }
}
