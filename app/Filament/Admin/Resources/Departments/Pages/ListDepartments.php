<?php

namespace App\Filament\Admin\Resources\Departments\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Departments\DepartmentResource;
use App\Support\StatusTabs;
use Filament\Resources\Pages\ListRecords;

class ListDepartments extends ListRecords
{
    protected static string $resource = DepartmentResource::class;

    // Departments are a fixed set, so there is no "New department" — but the guide is exactly
    // what a fixed set needs, since the question here is "why can't I add one".
    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
        ];
    }

    public function getTabs(): array
    {
        return StatusTabs::build(DepartmentResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'active' => ['label' => __('admin.tabs.active'), 'query' => fn ($query) => $query->where('is_active', true)],
            'inactive' => ['label' => __('admin.filters.inactive_only'), 'query' => fn ($query) => $query->where('is_active', false)],
        ]);
    }
}
