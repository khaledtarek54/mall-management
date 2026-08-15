<?php

namespace App\Filament\Admin\Resources\ServicePlans\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\ServicePlans\ServicePlanResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServicePlans extends ListRecords
{
    protected static string $resource = ServicePlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            CreateAction::make()->visible(fn () => ServicePlanResource::canCreate()),
        ];
    }

    /** Only active plans generate preventive work orders. */
    public function getTabs(): array
    {
        return StatusTabs::build(ServicePlanResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'active' => ['label' => __('admin.tabs.active'), 'query' => fn ($query) => $query->where('is_active', true), 'badge' => true, 'color' => 'success'],
            'inactive' => ['label' => __('admin.filters.inactive_only'), 'query' => fn ($query) => $query->where('is_active', false)],
        ]);
    }
}
