<?php

namespace App\Filament\Admin\Resources\Employees\Pages;

use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->visible(fn () => EmployeeResource::canCreate()),
        ];
    }

    /** Live headcount vs. the leavers kept on file. */
    public function getTabs(): array
    {
        return StatusTabs::build(EmployeeResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'active' => ['label' => __('admin.employees.statuses.active'), 'statuses' => ['active'], 'badge' => true, 'color' => 'success'],
            'terminated' => ['label' => __('admin.employees.statuses.terminated'), 'statuses' => ['terminated']],
        ]);
    }
}
