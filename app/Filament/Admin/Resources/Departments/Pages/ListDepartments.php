<?php

namespace App\Filament\Admin\Resources\Departments\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Filament\Admin\Resources\Departments\DepartmentResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDepartments extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = DepartmentResource::class;

    // The set is no longer fixed (D-6): a mall with its own Security or Tenant Relations team needs
    // somewhere to put it, and tenant requests ROUTE to a department. `CreateAction` is gated on
    // `departments.create` through the resource, like every other button here.
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
        return StatusTabs::build(DepartmentResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'active' => ['label' => __('admin.tabs.active'), 'query' => fn ($query) => $query->where('is_active', true)],
            'inactive' => ['label' => __('admin.filters.inactive_only'), 'query' => fn ($query) => $query->where('is_active', false)],
        ]);
    }
}
