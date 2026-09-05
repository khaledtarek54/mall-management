<?php

namespace App\Filament\Admin\Resources\Equipment\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Filament\Admin\Resources\Equipment\EquipmentResource;
use App\Filament\Imports\EquipmentImporter;
use App\Support\Imports;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListEquipment extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = EquipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(static::getResource()),
            // The register a mall arrives with is a spreadsheet. Without this it was the form, one
            // asset at a time — and equipment nobody enters means service plans with nothing to
            // attach to, so the whole preventive side of module 26 stays empty.
            ImportAction::make()
                ->importer(EquipmentImporter::class)
                ->label(__('admin.actions.import'))
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn () => Imports::allowed())
                ->authorize(fn () => Imports::allowed()),
            CreateAction::make(),
        ];
    }

    /** Decommissioned equipment stays on file but is not part of the live estate. */
    public function getTabs(): array
    {
        return StatusTabs::build(EquipmentResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'active' => ['label' => __('admin.tabs.active'), 'query' => fn ($query) => $query->where('is_active', true)],
            'inactive' => ['label' => __('admin.filters.inactive_only'), 'query' => fn ($query) => $query->where('is_active', false)],
        ]);
    }
}
