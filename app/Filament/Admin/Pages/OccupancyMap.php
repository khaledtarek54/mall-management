<?php

namespace App\Filament\Admin\Pages;

use App\Models\Asset;
use App\Support\AssignedAssets;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class OccupancyMap extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.occupancy-map';

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

    public function updatedAssetId(): void
    {
        // Livewire will re-render with the new asset
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.occupancy.nav_label');
    }

    public function getTitle(): string
    {
        return __('admin.occupancy.page_title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.operations');
    }

    protected function getViewData(): array
    {
        $allPropertiesMode = $this->isAllPropertiesMode();
        $assets = $allPropertiesMode
            ? $this->visibleAssets()->get(['id', 'name'])
            : collect();

        // Resolve the selected property, but only within the visible set — a
        // tampered ?asset= / wire value for an unassigned property is ignored.
        $asset = $this->isAssetVisible($this->assetId)
            ? $assets->firstWhere('id', $this->assetId) ?? Asset::query()->find($this->assetId)
            : $assets->first();

        if (! $asset) {
            return [
                'allPropertiesMode' => $allPropertiesMode,
                'assets' => $assets,
                'asset' => null,
                'units' => collect(),
                'stats' => [
                    'total' => 0, 'occupied' => 0, 'vacant' => 0, 'reserved' => 0, 'maintenance' => 0,
                ],
            ];
        }

        $units = $asset->units()
            ->with(['activeLease.tenant'])
            ->orderBy('floor')
            ->orderBy('code')
            ->get();

        return [
            'allPropertiesMode' => $allPropertiesMode,
            'assets' => $assets,
            'asset' => $asset,
            'units' => $units,
            'stats' => [
                'total' => $units->count(),
                'occupied' => $units->where('status', 'occupied')->count(),
                'vacant' => $units->where('status', 'vacant')->count(),
                'reserved' => $units->where('status', 'reserved')->count(),
                'maintenance' => $units->where('status', 'maintenance')->count(),
            ],
        ];
    }
}
