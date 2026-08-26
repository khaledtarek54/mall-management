<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Models\Asset;
use App\Models\Unit;
use App\Support\AssignedAssets;
use App\Support\Filament\EntitySelect;
use App\Support\ReportPreferences;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The leasing floor plan — every unit in a property as a card, grouped by floor,
 * coloured by status.
 *
 * Still a visual grid, but now a native Filament table in `contentGrid` layout
 * rather than hand-written CSS grid + inline hex colours. That keeps the
 * at-a-glance read AND adds what the bespoke markup never had: status filtering,
 * search by unit or tenant, and per-status counts.
 */
class OccupancyMap extends Page implements HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;
    use SavesReportViews;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected string $view = 'filament.pages.ledger-report';

    public ?int $assetId = null;

    /**
     * Who may read the mall's occupancy.
     *
     * **This page declared no gate at all until 2026-08-26**, and a Filament page with no
     * `canAccess()` is reachable by every authenticated panel user. It renders, per unit: the unit
     * code, the NAME OF THE TENANT trading in it (`activeLease.tenant.name` is eager-loaded and
     * printed on every occupied tile), the status, and a headline vacancy rate for the mall. Its two
     * neighbours in `Navigation::GROUPS['leasing']` — `RentRoll` and `ExpirationSchedule` — gate on
     * `reports.view`, and all three are registered side by side in `ReportCatalogue` as LEASING
     * reports. One of the three was open.
     *
     * What that meant in practice: `vendor` — an EXTERNAL maintenance contractor whose entire grant
     * is five keys wide, under a docblock in `RolesPermissionsSeeder` reading *"NO tenants/leases/
     * financials/HR/GL — it must not read another party's commercial data"* — could open this page
     * and read every retailer's name and the mall's vacancy rate. So could `technician`,
     * `coordinator`, `customer_service`, `marketing` and `hr`.
     *
     * It is invisible from the file, which is why it lasted: a missing method looks like nothing at
     * all, and everything else here is careful about PROPERTY scoping (`isAssetVisible()`, the
     * `whereRaw('1 = 0')` that keeps the page from falling open) — so the screen reads as one that
     * had been thought about.
     *
     * **The union of two rights, not one.** `reports.view` is how the catalogue's other leasing
     * reports gate; `units.view` is the underlying register's own right, and `operations` holds it
     * (the seeder gives that role the unit register deliberately) while holding no reports right —
     * a floor plan is an operational tool as much as a leasing one, which is why `Navigation` files
     * it beside the records rather than under Reports. Either claim is honest; holding neither is
     * exactly the set that should never have had it.
     *
     * No `Modules::enabled('reports')` clause, deliberately: this screen is reachable from the
     * leasing group as well as from the hub, and switching the reports module off should not take
     * the floor plan away from the people who reach it the other way. Its siblings, which are
     * reports and nothing else, keep theirs.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->canAny(['reports.view', 'units.view']) ?? false;
    }

    public function mount(): void
    {
        // If a specific property is the active tenant, lock the page to it —
        // the dropdown is only meaningful in "All Properties" mode.
        if (($tenantAssetId = TenantScope::currentAssetId()) !== null) {
            $this->assetId = $tenantAssetId;

            return;
        }

        $requested = (int) request()->query('asset', 0);

        // Never default to (or honor) a property the user isn't assigned to.
        $this->assetId = $this->isAssetVisible($requested)
            ? $requested
            : ($this->visibleAssets()->value('id'));

        ReportPreferences::restore($this);
    }

    public function isAllPropertiesMode(): bool
    {
        return TenantScope::currentAssetId() === null;
    }

    /**
     * Properties the current user may view in the map — their assigned set,
     * or every real property for super_admin / unassigned (back-compat).
     * The synthetic "All Properties" pseudo-asset is always excluded.
     *
     * @return Builder<Asset>
     */
    protected function visibleAssets(): Builder
    {
        $allowedIds = AssignedAssets::idsForCurrentUser();

        return Asset::query()
            ->where('code', '!=', Asset::ALL_PROPERTIES_CODE)
            ->when($allowedIds !== null, fn ($q) => $q->whereIn('id', $allowedIds))
            ->orderBy('name');
    }

    protected function isAssetVisible(?int $assetId): bool
    {
        return $assetId
            ? (clone $this->visibleAssets())->whereKey($assetId)->exists()
            : false;
    }

    /**
     * The property actually being mapped — the selection CLAMPED to the visible
     * set, falling back to the first one the user may see. A tampered ?asset= or
     * Livewire value for an unassigned property never resolves.
     */
    public function resolvedAssetId(): ?int
    {
        return $this->isAssetVisible($this->assetId)
            ? $this->assetId
            : $this->visibleAssets()->value('id');
    }

    /** The property picker — only meaningful when no single property is the active tenant. */
    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(['sm' => 2, 'lg' => 3])
                    ->visible(fn (): bool => $this->isAllPropertiesMode() && $this->visibleAssets()->count() > 1)
                    ->schema([
                        EntitySelect::make('assetId')
                            ->label(__('admin.occupancy.select_property'))
                            ->entity(Asset::class)
                            ->modifyOptionsQuery(fn ($query) => $query->whereIn('id', $this->visibleAssets()->pluck('id')))
                            ->live()
                            // Remembering happens HERE rather than through ReportFilters, because this picker is
                            // exempt from the shared component (see ReportFilters::EXEMPT) — the
                            // exemption is about the CONTROL, not about whether the choice is worth
                            // keeping. Wired at the only other place it can be.
                            ->afterStateUpdated(fn ($livewire) => ReportPreferences::remember($livewire)),
                    ]),
            ]);
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.occupancy.nav_label');
    }

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
            $this->saveViewAction(),
        ];
    }

    public function getTitle(): string
    {
        return __('admin.occupancy.page_title');
    }

    /** Occupancy rate is the headline the grid exists to support. */
    public function getSubheading(): ?string
    {
        $assetId = $this->resolvedAssetId();

        if ($assetId === null) {
            return __('admin.widgets.occupancy_grid.no_asset');
        }

        $units = Unit::query()->where('asset_id', $assetId);
        $total = (clone $units)->count();
        $occupied = (clone $units)->where('status', 'occupied')->count();
        $rate = $total > 0 ? round($occupied / $total * 100) : 0;

        return __('admin.widgets.occupancy_grid.occupancy_rate').": {$rate}% ({$occupied}/{$total})";
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                $assetId = $this->resolvedAssetId();

                return Unit::query()
                    ->with(['activeLease.tenant'])
                    // No visible property → no units. whereRaw(0=1) rather than
                    // an unscoped query: this page must never fall open.
                    ->when($assetId === null, fn ($q) => $q->whereRaw('1 = 0'))
                    ->when($assetId !== null, fn ($q) => $q->where('asset_id', $assetId));
            })
            ->columns([
                Stack::make([
                    TextColumn::make('code')
                        ->label(__('admin.tables.unit.code'))
                        ->weight('bold')
                        ->size('sm')
                        ->searchable(),
                    TextColumn::make('activeLease.tenant.name')
                        ->label(__('admin.tables.lease.tenant'))
                        ->size('xs')
                        ->color('gray')
                        ->searchable()
                        // Just a dash when vacant. Repeating the status here put
                        // the word "Vacant" on the card TWICE, once as the tenant
                        // line and again in the badge below it.
                        ->placeholder('—'),
                    TextColumn::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => __("admin.statuses.unit.{$state}"))
                        // Same semantics the hand-picked hex colours carried, but
                        // through the design system so dark mode and the
                        // per-property brand colour apply.
                        ->color(fn (string $state): string => match ($state) {
                            'occupied' => 'success',
                            'vacant' => 'danger',
                            'reserved' => 'warning',
                            default => 'gray',
                        }),
                ])->space(1),
            ])
            // The floor plan: dense enough to take a floor in at a glance. The
            // markup this replaced packed ~10 tiles per row (minmax 120px); four
            // wide turned a 50-unit mall into two screenfuls.
            ->contentGrid(['sm' => 2, 'md' => 4, 'lg' => 5, 'xl' => 6, '2xl' => 8])
            ->groups([
                Group::make('floor.code')
                    ->label(__('admin.pdf.floor'))
                    ->titlePrefixedWithLabel()
                    // Grouped and ordered by the property's floor REGISTER. This replaced a
                    // three-clause `orderByRaw` (a CASE for 'ground', then `length()`, then the
                    // value) that got the common case right — Ground → 1 → 2 → 10 — and then sorted
                    // a BASEMENT after the tenth floor, because the CASE only knew about the ground
                    // floor. It was raw SQL on `lower()`/`length()` (the cross-database hazard this
                    // project has hit twice) and it lived only here, so every other consumer of the
                    // free-text column still got plain string order.
                    //
                    // The register answers it once for everyone: `floors.level` is set when the
                    // property is set up, and a unit merely points at it. Unfloored units sort last
                    // — a unit with no floor is not the ground floor.
                    // A correlated subquery, not a join: the page's own property scoping filters on
                    // an unqualified `asset_id`, and joining `floors` makes that ambiguous — the
                    // table has one too. This leaves the base query's shape untouched.
                    //
                    // It is raw SQL again, but not the kind that was removed: the old expression
                    // encoded floor NAMING in SQL (a CASE listing 'ground', 'g', '0') and had to
                    // grow for every label an operator invented. This encodes only "order by the
                    // floor's level, unfloored last", and `coalesce` + a scalar subquery behave the
                    // same on MySQL and SQLite.
                    ->orderQueryUsing(fn (Builder $query) => $query->orderByRaw(
                        'coalesce((select level from floors where floors.id = units.floor_id), 9999)'
                    )),
            ])
            ->defaultGroup('floor.code')
            ->defaultSort('code')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn (): array => __('admin.statuses.unit'))
                    ->multiple(),
            ])
            // Tenant passed explicitly: the admin panel's routes are
            // tenant-scoped, and resolving it from ambient context makes the
            // link depend on where the page happens to be rendered from.
            ->recordUrl(fn (Unit $record): ?string => $record->asset_id
                ? UnitResource::getUrl('edit', ['record' => $record], tenant: $record->asset)
                : null)
            // A floor plan shows the WHOLE property. Paginating split a 50-unit
            // mall across two pages, which defeats the one thing this page is
            // for — and made the occupancy figure in the subheading disagree
            // with what was on screen.
            ->paginated(false)
            ->emptyStateIcon('heroicon-o-squares-2x2')
            ->emptyStateHeading(__('admin.widgets.occupancy_grid.no_asset'));
    }
}
