<?php

namespace App\Filament\Admin\Resources\Warehouses\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Filament\Admin\Resources\Warehouses\WarehouseResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWarehouses extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = WarehouseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(static::getResource()),
            CreateAction::make()->visible(fn () => WarehouseResource::canCreate()),
        ];
    }

    public function getTabs(): array
    {
        return StatusTabs::build(WarehouseResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'active' => ['label' => __('admin.tabs.active'), 'query' => fn ($query) => $query->where('is_active', true)],
            'inactive' => ['label' => __('admin.filters.inactive_only'), 'query' => fn ($query) => $query->where('is_active', false)],
        ]);
    }
}
