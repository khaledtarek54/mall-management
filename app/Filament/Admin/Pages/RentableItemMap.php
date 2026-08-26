<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Pages\Concerns\MapsOneProperty;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Filament\Admin\Resources\RentableItems\RentableItemResource;
use App\Models\RentableItem;
use App\Support\Filament\FloorGrouping;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The floor plan for everything that is LET but is not a shop — parking bays, storage, signage
 * faces and kiosks, grouped by floor and coloured by whether they are earning.
 *
 * `OccupancyMap` has always answered *"which shops are vacant"*. There was no counterpart for the
 * other register: the only way to find a free bay was the rentable-items LIST, filtered by status —
 * and until the projection fix that shipped alongside this page, that filter under-reported, because
 * a bay whose lease had expired still read `assigned` for ever. A map over an untrustworthy status
 * column would have drawn the same wrong answer more convincingly, which is why the column was
 * fixed first.
 *
 * **A separate page rather than a mode of the unit map, deliberately.** Yardi treats parking as its
 * own space type with its own register (`docs/benchmarks/yardi/09-yardi-space-and-parking.md`), and
 * this codebase already says the same thing in its own words: `Floor::areaFigures()` excludes these
 * items because *"a parking bay is not a unit"*. They have different holders, different pricing and
 * a different vacancy conversation. What IS shared is shared properly — `MapsOneProperty` resolves
 * the property for both maps and `FloorGrouping` orders both by the floor register, so the two can
 * never disagree about who may see which mall or about where a basement sorts.
 */
class RentableItemMap extends Page implements HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;
    use MapsOneProperty;
    use SavesReportViews;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected string $view = 'filament.pages.ledger-report';

    public ?int $assetId = null;

    /**
     * The same union its sibling map takes, over this register's own right.
     *
     * `rentable_items.view` is the register's permission — `leasing` holds it and maintains the
     * bays — and `reports.view` is how the catalogue's other leasing reports gate. Holding neither
     * is exactly the set that should not be reading which of a mall's bays are let and to whom:
     * this page names the holder on every occupied tile, the same commercial data the occupancy map
     * was left open on until 2026-08-26.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->canAny(['rentable_items.view', 'reports.view']) ?? false;
    }

    public function mount(): void
    {
        $this->mountPropertyMap();
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.rentable_item_map.nav_label');
    }

    public function getTitle(): string
    {
        return __('admin.rentable_item_map.page_title');
    }

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
            $this->saveViewAction(),
        ];
    }

    /**
     * The headline the grid exists to support: how much of the register is earning.
     *
     * **Out-of-service items are excluded from the denominator, not counted as vacant.** A bay
     * closed for resurfacing is not lost letting — treating it as vacant makes a mall look worse the
     * more diligently it maintains its car park, and the operator cannot act on it either way. It is
     * reported separately so it does not simply vanish from the count.
     */
    public function getSubheading(): ?string
    {
        $assetId = $this->resolvedAssetId();

        if ($assetId === null) {
            return __('admin.widgets.occupancy_grid.no_asset');
        }

        $items = RentableItem::query()->where('asset_id', $assetId);

        $lettable = (clone $items)->where('status', '!=', RentableItem::STATUS_OUT_OF_SERVICE)->count();
        $let = (clone $items)->where('status', RentableItem::STATUS_ASSIGNED)->count();
        $outOfService = (clone $items)->where('status', RentableItem::STATUS_OUT_OF_SERVICE)->count();

        return __('admin.rentable_item_map.subheading', [
            'rate' => $lettable > 0 ? round($let / $lettable * 100) : 0,
            'let' => $let,
            'lettable' => $lettable,
            'out_of_service' => $outOfService,
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                $assetId = $this->resolvedAssetId();

                return RentableItem::query()
                    // Both holder kinds, with their counterparty — `currentHolderLabel()` resolves
                    // against the LOADED relations, so this is what keeps a 50-bay map to a handful
                    // of queries instead of one per card.
                    ->with(['floor', 'leases.tenant', 'ownerships.tenant'])
                    // No visible property → nothing. `whereRaw(0=1)` rather than an unscoped query:
                    // this page must never fall open, the same rule the unit map states.
                    ->when($assetId === null, fn ($q) => $q->whereRaw('1 = 0'))
                    ->when($assetId !== null, fn ($q) => $q->where('asset_id', $assetId));
            })
            ->columns([
                Stack::make([
                    TextColumn::make('code')
                        ->label(__('admin.fields.item_code'))
                        ->weight('bold')
                        ->size('sm')
                        ->searchable(),
                    // The TYPE earns a line here where it does not on the unit map: this grid mixes
                    // bays, stores, signage faces and kiosks, and "P-014" alone does not say which.
                    TextColumn::make('type')
                        ->label(__('admin.fields.item_type'))
                        ->size('xs')
                        ->color('gray')
                        ->formatStateUsing(fn ($state): string => __('admin.enums.rentable_item_type')[$state] ?? $state),
                    // Who has it TODAY, through the one predicate `isSpokenFor()` uses — so the card
                    // can never name a holder for an item this same page colours as available.
                    TextColumn::make('holder')
                        ->label(__('admin.tables.invoice.tenant'))
                        ->size('xs')
                        ->color('gray')
                        ->state(fn (RentableItem $record): ?string => $record->currentHolderLabel())
                        ->placeholder('—'),
                    TextColumn::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => __('admin.enums.rentable_item_status')[$state] ?? $state)
                        // The same three colours the register uses, and they mean the same thing:
                        // green is earning, amber is free to let, red is off the market.
                        ->color(fn (string $state): string => match ($state) {
                            RentableItem::STATUS_ASSIGNED => 'success',
                            RentableItem::STATUS_OUT_OF_SERVICE => 'danger',
                            default => 'warning',
                        }),
                ])->space(1),
            ])
            ->contentGrid(['sm' => 2, 'md' => 4, 'lg' => 5, 'xl' => 6, '2xl' => 8])
            ->groups([FloorGrouping::make('rentable_items')])
            ->defaultGroup('floor.code')
            ->defaultSort('code')
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.fields.item_type'))
                    ->options(fn (): array => __('admin.enums.rentable_item_type'))
                    ->multiple(),
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn (): array => __('admin.enums.rentable_item_status'))
                    ->multiple(),
            ])
            // Tenant passed explicitly: the admin panel's routes are tenant-scoped, and resolving it
            // from ambient context makes the link depend on where the page is rendered from.
            ->recordUrl(fn (RentableItem $record): ?string => $record->asset_id
                ? RentableItemResource::getUrl('edit', ['record' => $record], tenant: $record->asset)
                : null)
            // A floor plan shows the WHOLE register. Paginating splits a car park across pages and
            // destroys the at-a-glance read the page exists for.
            ->paginated(false)
            ->emptyStateHeading(__('admin.rentable_item_map.empty_heading'))
            ->emptyStateDescription(__('admin.rentable_item_map.empty_description'));
    }
}
