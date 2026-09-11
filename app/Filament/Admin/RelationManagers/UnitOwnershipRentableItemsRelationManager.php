<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Actions\RentableItemHoldingActions;
use App\Models\RentableItem;
use App\Models\UnitOwnership;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The parking bays, stores and signage an owner-occupier holds alongside the unit he bought.
 *
 * **Voyager's model, not an extension of it.** Rentable items are assigned to the customer RECORD
 * (`docs/benchmarks/yardi/09-yardi-space-and-parking.md` §2 — "assign Rentable Items … to both new
 * and existing residents"), and in Voyager Condo/Co-Op the unit owner IS that record. Atriom had
 * narrowed "customer record" to "lease" only because a lease was the only agreement that existed
 * when rentable items were built. Operator's decision (2026-08-19): an owner can hold a bay, and
 * its charge rides his monthly صيانة assessment — the same way a tenant's rides the lease schedule.
 *
 * **Deliberately the same screen as the lease's**, down to the columns and the empty state. An
 * operator who has let a bay to a tenant already knows how to let one to an owner; making the owner
 * version look like a different feature would be inventing a distinction the business does not
 * have. Both surfaces call the one service and share one picker
 * (`App\Support\RentableItemOptions`) — the previous duplicate is exactly what drifted.
 */
class UnitOwnershipRentableItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'rentableItems';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.lease_rentable_items.title');
    }

    public function table(Table $table): Table
    {
        return $table
            // No search box: RentableItem carries no search blob and no column here is searchable,
            // so TableDefaults would render one that always returns nothing.
            ->searchable(false)
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.fields.item_code'))
                    ->fontFamily('mono')
                    ->size('xs'),

                TextColumn::make('type')
                    ->label(__('admin.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('admin.enums.rentable_item_type')[$state] ?? $state),

                // The NEGOTIATED rate off the pivot, not the item's asking rate — what this owner
                // actually pays is the only figure that reconciles with his parking charge.
                TextColumn::make('pivot.monthly_rate')
                    ->label(__('admin.fields.item_monthly_rate'))
                    ->money('EGP'),

                TextColumn::make('pivot.effective_from')
                    ->label(__('admin.fields.held_from'))
                    ->date('d/m/Y'),

                TextColumn::make('pivot.effective_to')
                    ->label(__('admin.fields.held_until'))
                    ->date('d/m/Y')
                    ->placeholder(__('admin.lease_rentable_items.still_held'))
                    ->badge()
                    ->color(fn ($state) => $state === null ? 'success' : 'gray'),
            ])
            // The SAME two acts the lease's tab carries, from one definition — bound to this
            // ownership the way `LeaseActions::forOwner()` binds a lease.
            ->headerActions([RentableItemHoldingActions::assign()->record($this->ownership())])
            ->recordActions([RentableItemHoldingActions::releaseRow()])
            ->defaultSort('rentable_item_holdings.effective_from', 'desc')
            ->emptyStateIcon('heroicon-o-ticket')
            ->emptyStateHeading(__('admin.lease_rentable_items.empty_heading'))
            ->emptyStateDescription(__('admin.unit_ownerships.rentable_items_empty'));
    }

    /**
     * The owner record, typed.
     *
     * `getOwnerRecord()` returns the base `Model`, so every use of an ownership attribute reads as
     * an error. Narrowed once here rather than cast at each call site.
     */
    protected function ownership(): UnitOwnership
    {
        /** @var UnitOwnership $ownership */
        $ownership = $this->getOwnerRecord();

        return $ownership;
    }
}
