<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\Units\UnitResource;
use App\Models\Asset;
use App\Models\Unit;
use App\Support\AssignedAssets;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Forms\Components\Select;
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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.ledger-report';

    public ?int $assetId = null;

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
                        Select::make('assetId')
                            ->label(__('admin.occupancy.select_property'))
                            ->options(fn (): array => $this->visibleAssets()->pluck('name', 'id')->all())
                            ->native(false)
                            ->live(),
                    ]),
            ]);
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.occupancy.nav_label');
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

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.leasing');
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
                        ->weight('bold')
                        ->size('sm')
                        ->searchable(),
                    TextColumn::make('activeLease.tenant.name')
                        ->size('xs')
                        ->color('gray')
                        ->searchable()
                        // A vacant unit has no tenant — say the status instead, as
                        // the old grid did.
                        ->placeholder(fn (Unit $record): string => __("admin.statuses.unit.{$record->status}")),
                    TextColumn::make('status')
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
            // The floor plan: cards in a grid, one block per floor.
            ->contentGrid(['md' => 2, 'lg' => 3, 'xl' => 4])
            ->groups([
                Group::make('floor')
                    ->label(__('admin.pdf.floor'))
                    ->titlePrefixedWithLabel(),
            ])
            ->defaultGroup('floor')
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
            ->paginated([24, 48, 96, 'all'])
            ->defaultPaginationPageOption(48)
            ->emptyStateIcon('heroicon-o-squares-2x2')
            ->emptyStateHeading(__('admin.widgets.occupancy_grid.no_asset'));
    }
}
