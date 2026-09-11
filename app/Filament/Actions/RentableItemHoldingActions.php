<?php

namespace App\Filament\Actions;

use App\Contracts\BillableAgreement;
use App\Models\Lease;
use App\Models\RentableItem;
use App\Services\AssignRentableItemService;
use App\Support\ChargeEscalation;
use App\Support\Filament\EscalationRuleFields;
use App\Support\RentableItemOptions;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Letting a bay, kiosk, store or sign to a HOLDER — and taking it back — whichever agreement holds it.
 *
 * A rentable item is assigned to the customer RECORD (Voyager: "assign Rentable Items … to both
 * new and existing residents"), and here that record is a `BillableAgreement`: a lease, or a unit
 * ownership. `AssignRentableItemService` and `RentableItemOptions` were holder-agnostic from the
 * day the ownership arm shipped; the ACTS were not. `LeaseActions` carried the lease's assign and
 * release, `UnitOwnershipRentableItemsRelationManager` carried a copy of each with `$record`
 * replaced by `$this->ownership()`, and both tabs carried a per-row release of their own — five
 * definitions of two acts. Measured 2026-09-11, the copies had already drifted: the lease's picker
 * says *"nothing free"* on an empty list where the ownership's showed Filament's bare *"No
 * options"*, and each tab's `visible()` restated the service's own liveness rule
 * (`OPEN_TO_COMMERCIAL_ACTS` in one file, `isTerminal()` in the other) — exactly the drift the
 * lease tab's own comment claimed had been closed in August.
 *
 * ## One definition, three surfaces
 *
 * - `assign()` and `release()` take the HOLDER as `$record` — Filament injects the page's record
 *   on a header, and `->record($holder)` binds it on a tab (`LeaseActions::forOwner()`). They are
 *   the two members `LeaseActions` composes under its *premises* group.
 * - `releaseRow()` sits on the holding tab's ROW, where `$record` is the ITEM; the holder is the
 *   tab's owner record.
 *
 * The button's `visible()` and the service's refusal read ONE predicate,
 * {@see AssignRentableItemService::holderCanTakeOn()}, so a tab cannot offer what the service
 * will refuse. The permission is `rentable_items.edit` on every surface: what decides whether you
 * may let a bay is your right over the bay register, not which agreement you reached it from.
 */
final class RentableItemHoldingActions
{
    /** Named once so `visible()` and `authorize()` cannot drift — the project's double-gate rule. */
    public static function canWrite(): bool
    {
        return auth()->user()?->can('rentable_items.edit') ?? false;
    }

    /**
     * Assign an item to the holder. Writes the dated pivot AND re-derives the holder's one
     * `parking` charge, so the money follows in the same click.
     */
    public static function assign(): Action
    {
        return Action::make('assignRentableItem')
            ->label(__('admin.actions.assign_rentable_item'))
            ->icon('heroicon-o-ticket')
            ->color('gray')
            ->modalHeading(fn (BillableAgreement $record): string => __('admin.actions.assign_rentable_item').' · '.$record->reference)
            ->modalDescription(__('admin.actions.assign_rentable_item_hint'))
            ->visible(fn (BillableAgreement $record): bool => self::canWrite()
                && app(AssignRentableItemService::class)->holderCanTakeOn($record))
            ->authorize(fn (): bool => self::canWrite())
            ->schema(fn (BillableAgreement $record): array => [
                Select::make('rentable_item_id')
                    ->label(__('admin.resources.rentable_item.singular'))
                    ->options(fn (): array => RentableItemOptions::lettable($record))
                    ->native(false)
                    ->searchable()
                    // An empty list here means every bay, sign and store in the property is either
                    // out of service or already let — including to THIS holder. Filament's own "No
                    // options" leaves the operator unable to tell that from a broken screen.
                    ->noSearchResultsMessage(__('admin.rentable_items.none_free'))
                    ->placeholder(__('admin.rentable_items.none_free_placeholder'))
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
                // How the item steps on the anniversary (2026-09-12) — its own rule, stored on
                // the holding, proposed as the property proposes a new charge. The SAME trio the
                // lease create form's items table and the tab's "Annual increase" row action
                // build, so an item let from any door carries the same shape of rule.
                ...self::ruleFields($record),
            ])
            ->action(function (BillableAgreement $record, array $data): void {
                abort_unless(self::canWrite(), 403);

                $item = RentableItem::findOrFail($data['rentable_item_id']);

                try {
                    app(AssignRentableItemService::class)->assign($record, $item, $data);
                } catch (DomainException|InvalidArgumentException $e) {
                    // A refusal is a message, not a 500 — and it says what to do next.
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()
                    ->title(__('admin.actions.assign_rentable_item_done', ['code' => $item->code]))
                    ->send();
            });
    }

    /** Give an item back, picked from what the holder still holds — the header form of the act. */
    public static function release(): Action
    {
        return Action::make('releaseRentableItem')
            ->label(__('admin.actions.release_rentable_item'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->modalDescription(__('admin.actions.release_rentable_item_hint'))
            ->visible(fn (BillableAgreement $record): bool => self::canWrite()
                && $record->rentableItems()->wherePivotNull('effective_to')->exists())
            ->authorize(fn (): bool => self::canWrite())
            ->schema(fn (BillableAgreement $record): array => [
                Select::make('rentable_item_id')
                    ->label(__('admin.resources.rentable_item.singular'))
                    ->options(fn (): array => RentableItemOptions::held($record))
                    ->native(false)
                    ->required(),
                self::effectiveTo(),
            ])
            ->action(function (BillableAgreement $record, array $data): void {
                abort_unless(self::canWrite(), 403);

                self::doRelease($record, RentableItem::findOrFail($data['rentable_item_id']), $data['effective_to']);
            });
    }

    /**
     * Give THIS item back — the row form of the same act, on the holding tab.
     *
     * The holder is the tab's owner record; the row is the item. Offered only while the holding
     * is open — releasing a bay already given back is meaningless, and the service refuses it
     * anyway.
     */
    public static function releaseRow(): Action
    {
        return Action::make('release')
            ->label(__('admin.actions.release_rentable_item'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->modalDescription(__('admin.actions.release_rentable_item_hint'))
            ->visible(fn (RentableItem $record): bool => self::canWrite()
                && $record->getRelationValue('pivot')?->effective_to === null)
            ->authorize(fn (): bool => self::canWrite())
            ->schema([self::effectiveTo()])
            ->action(function (RentableItem $record, RelationManager $livewire, array $data): void {
                abort_unless(self::canWrite(), 403);

                /** @var BillableAgreement&Model $holder */
                $holder = $livewire->getOwnerRecord();

                self::doRelease($holder, $record, $data['effective_to']);
            });
    }

    /**
     * The annual-increase trio for a held item, proposed as the property proposes a new charge.
     *
     * A *follows-lease* rule names what the item inherits from THIS lease's clause, so the fields
     * are built against the lease where the holder is one; a unit ownership carries no clause and
     * gets the modes that stand on their own. Public: the lease's own Parking & rentable items tab
     * builds its "Annual increase" row action from it.
     *
     * @return array<int, Component>
     */
    public static function ruleFields(BillableAgreement $record): array
    {
        [$mode, $rate, $amount] = EscalationRuleFields::make($record instanceof Lease ? $record : null);
        $mode->default(fn (): string => ChargeEscalation::defaultModeFor($record->assetId()));

        return [$mode, $rate, $amount];
    }

    private static function effectiveTo(): DatePicker
    {
        return DatePicker::make('effective_to')
            ->label(__('admin.actions.release_rentable_item_to'))
            ->default(now()->endOfMonth())
            ->required()
            ->helperText(__('admin.actions.release_rentable_item_to_hint'));
    }

    private static function doRelease(BillableAgreement $holder, RentableItem $item, mixed $effectiveTo): void
    {
        try {
            app(AssignRentableItemService::class)->release($holder, $item, $effectiveTo);
        } catch (DomainException|InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()
            ->title(__('admin.actions.release_rentable_item_done', ['code' => $item->code]))
            ->send();
    }
}
