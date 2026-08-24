<?php

namespace App\Filament\Admin\Resources\Assets\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Assets\AssetResource;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAssets extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = AssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return StatusTabs::build(AssetResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'active' => ['label' => __('admin.tabs.active'), 'query' => fn ($query) => $query->where('is_active', true)],
            'inactive' => ['label' => __('admin.filters.inactive_only'), 'query' => fn ($query) => $query->where('is_active', false)],
        ]);
    }
}
