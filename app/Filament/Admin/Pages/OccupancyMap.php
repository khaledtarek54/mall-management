<?php

namespace App\Filament\Admin\Pages;

use App\Models\Asset;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class OccupancyMap extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.occupancy-map';

    public ?int $assetId = null;

    public function mount(): void
    {
        $this->assetId = (int) request()->query('asset', Asset::query()->orderBy('id')->value('id'));
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
        $assets = Asset::query()->orderBy('name')->get(['id', 'name']);
        $asset = $this->assetId
            ? Asset::query()->find($this->assetId)
            : $assets->first();

        if (! $asset) {
            return [
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
