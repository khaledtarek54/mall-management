<?php

namespace App\Filament\Admin\Resources\Employees\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Filament\Imports\EmployeeImporter;
use App\Support\Imports;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployees extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(static::getResource()),
            ImportAction::make()
                ->importer(EmployeeImporter::class)
                ->label(__('admin.actions.import'))
                ->icon('heroicon-o-arrow-up-tray')
                // Import is admin-only (FR-USR-02) and is NOT a flavour of create — one wrong CSV
                // column rewrites the whole register. Gated in both places, through the one
                // registry, so the import buttons cannot drift apart.
                ->visible(fn () => Imports::allowed())
                ->authorize(fn () => Imports::allowed()),
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
