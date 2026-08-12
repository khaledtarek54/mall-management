<?php

namespace App\Filament\Admin\Resources\SlaPolicies\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\SlaPolicies\SlaPolicyResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSlaPolicies extends ListRecords
{
    protected static string $resource = SlaPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()), CreateAction::make()];
    }

    /** Only active policies are applied when a request or work order is raised. */
    public function getTabs(): array
    {
        return StatusTabs::build(SlaPolicyResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'active' => ['label' => __('admin.tabs.active'), 'query' => fn ($query) => $query->where('is_active', true)],
            'inactive' => ['label' => __('admin.filters.inactive_only'), 'query' => fn ($query) => $query->where('is_active', false)],
        ]);
    }
}
