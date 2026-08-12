<?php

namespace App\Filament\Admin\Resources\Units\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Filament\Imports\UnitImporter;
use App\Support\Imports;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListUnits extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = UnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(static::getResource()),
            ImportAction::make()
                ->importer(UnitImporter::class)
                ->label(__('admin.actions.import'))
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn () => Imports::allowed())
                ->authorize(fn () => Imports::allowed()),
            CreateAction::make(),
        ];
    }

    /** The leasing floor at a glance — vacancy is the number this module exists to move. */
    public function getTabs(): array
    {
        return StatusTabs::build(UnitResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'vacant' => ['label' => __('admin.statuses.unit.vacant'), 'statuses' => ['vacant'], 'badge' => true, 'color' => 'danger'],
            'reserved' => ['label' => __('admin.statuses.unit.reserved'), 'statuses' => ['reserved'], 'badge' => true, 'color' => 'warning'],
            'occupied' => ['label' => __('admin.statuses.unit.occupied'), 'statuses' => ['occupied']],
            'maintenance' => ['label' => __('admin.statuses.unit.maintenance'), 'statuses' => ['maintenance'], 'badge' => true, 'color' => 'gray'],
        ]);
    }
}
