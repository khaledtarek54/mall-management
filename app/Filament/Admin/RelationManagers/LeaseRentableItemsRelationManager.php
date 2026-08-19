<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\Actions\LeaseActions;
use App\Models\Lease;
use App\Models\RentableItem;
use App\Services\AssignRentableItemService;
use App\Support\RentableItemOptions;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The parking bays, stores and signage faces a lease holds — on the lease itself.
 *
 * **Why this exists.** Assign and Release already worked, as row actions in the leases *list*
 * overflow menu, and the money followed correctly. But a lease's own page showed nothing: seven
 * relation managers and none for the space it rents beyond the premises. So an operator could let a
 * bay and then had no way to see they had — the assignment existed only as a line on an invoice.
 * Working business logic with no surface is indistinguishable from a missing feature, and this was
 * reported as exactly that.
 *
 * The actions live here as well as on the list, because this is where someone asking "what does this
 * tenant have?" actually looks. Both call the same service; neither re-implements the rule.
 */
class LeaseRentableItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'rentableItems';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.lease_rentable_items.title');
    }

    public function table(Table $table): Table
    {
        return $table
            // No search box: RentableItem carries no search blob and no column here is searchable.
            // TableDefaults would otherwise render one that always returns nothing.
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

                // The NEGOTIATED rate off the pivot, not the item's asking rate — what this lease
                // actually pays is the only figure that reconciles with the parking charge.
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
            // The SAME assign action the lease header and the leases list carry — composed from
            // App\Filament\Admin\Actions\LeaseActions rather than declared again here.
            //
            // It was a second copy with its own form, and the two had already drifted: this one
            // picked the item with a plain `Select`, where the registry uses an `EntitySelect` — so
            // the same act searched one raw column here and the folded blob there, and only one of
            // them could find an item by anything but its name (2026-08-18).
            ->headerActions(LeaseActions::forOwner($this->lease(), ['assignRentableItem']))
            ->recordActions([
                Action::make('release')
                    ->label(__('admin.actions.release_rentable_item'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->modalDescription(__('admin.actions.release_rentable_item_hint'))
                    // Only what is still held — releasing a bay already given back is meaningless,
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
                                ->release($this->lease(), $record, $data['effective_to']);
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
            ->emptyStateDescription(__('admin.lease_rentable_items.empty_description'));
    }

    /**
     * The owner record, typed.
     *
     * `getOwnerRecord()` returns the base `Model`, so every use of a lease attribute or a call
     * into a lease-typed service reads as an error. Narrowed once here rather than with a cast at
     * each of the four call sites.
     */
    protected function lease(): Lease
    {
        /** @var Lease $lease */
        $lease = $this->getOwnerRecord();

        return $lease;
    }

    /** Named once so `visible()` and `authorize()` cannot drift — the project's double-gate rule. */
    protected function canWrite(): bool
    {
        return auth()->user()?->can('rentable_items.edit') ?? false;
    }

    /**
     * Items this lease could take — through the shared, holder-agnostic list.
     *
     * This method used to build the list itself, and that copy is what drifted from the one in
     * `LeaseActions`. One answer now, whichever surface asks.
     *
     * @return array<int, string>
     */
    protected function lettableOptions(): array
    {
        return RentableItemOptions::lettable($this->lease());
    }
}
