<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\RelationManagers\Concerns\CountsItsRows;
use App\Filament\Admin\Resources\RentableItems\RentableItemResource;
use App\Models\RentableItem;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The property's parking bays, storage rooms and signage faces — and who holds each one.
 *
 * The property page listed its floors, units and staff but never its rentable items, so "how many
 * bays are still free?" — the question that decides whether you can promise one to a prospect —
 * could only be answered from a separate register filtered by hand.
 *
 * Read-only: an item is created in its own resource, where the code and rate belong, and it is LET
 * from the lease (see {@see LeaseRentableItemsRelationManager}) because letting is a lease decision.
 * This is the occupancy picture.
 */
class AssetRentableItemsRelationManager extends RelationManager
{
    use CountsItsRows;

    protected static string $relationship = 'rentableItems';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.asset_rentable_items.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.fields.item_code'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->searchable(),

                TextColumn::make('type')
                    ->label(__('admin.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('admin.enums.rentable_item_type')[$state] ?? $state),

                TextColumn::make('monthly_rate')
                    ->label(__('admin.fields.item_monthly_rate'))
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('admin.filters.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('admin.enums.rentable_item_status')[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        RentableItem::STATUS_AVAILABLE => 'success',
                        RentableItem::STATUS_ASSIGNED => 'gray',
                        default => 'danger',
                    }),

                // Who has it right now. Derived from the dated pivot rather than the status column,
                // so it answers "held by whom today" and not merely "held at some point".
                TextColumn::make('holder')
                    ->label(__('admin.asset_rentable_items.held_by'))
                    ->state(fn (RentableItem $record) => $record->leases()
                        ->wherePivotNull('effective_to')
                        ->with('tenant')
                        ->get()
                        ->map(fn ($lease) => $lease->tenant?->storeName() ?? $lease->reference)
                        ->join(', '))
                    ->placeholder(__('admin.asset_rentable_items.free')),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.fields.type'))
                    ->options(fn () => __('admin.enums.rentable_item_type')),
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.enums.rentable_item_status')),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('admin.actions.open'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (RentableItem $record): string => RentableItemResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (RentableItem $record): bool => RentableItemResource::canEdit($record)),
            ])
            ->defaultSort('code')
            ->emptyStateIcon('heroicon-o-ticket')
            ->emptyStateHeading(__('admin.asset_rentable_items.empty_heading'))
            ->emptyStateDescription(__('admin.asset_rentable_items.empty_description'));
    }
}
