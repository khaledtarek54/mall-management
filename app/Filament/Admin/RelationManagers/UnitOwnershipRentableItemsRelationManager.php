<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\RentableItem;
use App\Models\UnitOwnership;
use App\Services\AssignRentableItemService;
use App\Support\RentableItemOptions;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
            ->headerActions([
                Action::make('assignRentableItem')
                    ->label(__('admin.actions.assign_rentable_item'))
                    ->icon('heroicon-o-ticket')
                    ->color('gray')
                    ->modalHeading(fn () => __('admin.actions.assign_rentable_item').' · '.$this->ownership()->reference)
                    ->modalDescription(__('admin.actions.assign_rentable_item_hint'))
                    ->visible(fn (): bool => $this->canWrite() && ! $this->ownership()->status->isTerminal())
                    ->authorize(fn (): bool => $this->canWrite())
                    ->schema(fn (): array => [
                        Select::make('rentable_item_id')
                            ->label(__('admin.resources.rentable_item.singular'))
                            ->options(fn (): array => RentableItemOptions::lettable($this->ownership()))
                            ->native(false)
                            ->searchable()
                            ->required()
                            ->helperText(__('admin.helpers.assign_rentable_item')),
                        DatePicker::make('effective_from')
                            ->label(__('admin.actions.change_rent_effective_from'))
                            ->default(now()->startOfMonth())
                            ->required(),
                        TextInput::make('monthly_rate')
                            ->label(__('admin.fields.item_monthly_rate'))
                            ->prefix('EGP')
                            ->numeric()
                            ->minValue(0)
                            ->helperText(__('admin.helpers.assign_rentable_item_rate')),
                    ])
                    ->action(function (array $data): void {
                        // The real gate. `visible()` is the UI half and both name the same
                        // predicate so they cannot drift — the project's double-gate rule.
                        abort_unless($this->canWrite(), 403);

                        $item = RentableItem::findOrFail($data['rentable_item_id']);

                        try {
                            app(AssignRentableItemService::class)->assign($this->ownership(), $item, $data);
                        } catch (\DomainException|\InvalidArgumentException $e) {
                            // A refusal is a message, not a 500 — and it says what to do next.
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title(__('admin.actions.assign_rentable_item_done', ['code' => $item->code]))
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('release')
                    ->label(__('admin.actions.release_rentable_item'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->modalDescription(__('admin.actions.release_rentable_item_hint'))
                    // Only what is still held — giving back a bay already released is meaningless,
                    // and the service refuses it anyway.
                    ->visible(fn (RentableItem $record): bool => $this->canWrite()
                        && $record->getRelationValue('pivot')?->effective_to === null)
                    ->authorize(fn (): bool => $this->canWrite())
                    ->schema([
                        DatePicker::make('effective_to')
                            ->label(__('admin.actions.release_rentable_item_to'))
                            ->default(now()->endOfMonth())
                            ->required()
                            ->helperText(__('admin.actions.release_rentable_item_to_hint')),
                    ])
                    ->action(function (RentableItem $record, array $data): void {
                        abort_unless($this->canWrite(), 403);

                        try {
                            app(AssignRentableItemService::class)
                                ->release($this->ownership(), $record, $data['effective_to']);
                        } catch (\DomainException|\InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title(__('admin.actions.release_rentable_item_done', ['code' => $record->code]))
                            ->send();
                    }),
            ])
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

    /** Named once so `visible()` and `authorize()` cannot drift — the project's double-gate rule. */
    protected function canWrite(): bool
    {
        return auth()->user()?->can('rentable_items.edit') ?? false;
    }
}
