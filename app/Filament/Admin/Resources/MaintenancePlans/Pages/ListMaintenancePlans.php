<?php

namespace App\Filament\Admin\Resources\MaintenancePlans\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\MaintenancePlans\MaintenancePlanResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMaintenancePlans extends ListRecords
{
    protected static string $resource = MaintenancePlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            CreateAction::make()->visible(fn () => MaintenancePlanResource::canCreate()),
        ];
    }

    /** Only active plans generate preventive work orders. */
    public function getTabs(): array
    {
        return StatusTabs::build(MaintenancePlanResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'active' => ['label' => __('admin.tabs.active'), 'query' => fn ($query) => $query->where('is_active', true), 'badge' => true, 'color' => 'success'],
            'inactive' => ['label' => __('admin.filters.inactive_only'), 'query' => fn ($query) => $query->where('is_active', false)],
        ]);
    }
}
