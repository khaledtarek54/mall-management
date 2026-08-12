<?php

namespace App\Filament\Admin\Resources\UtilityMeters\Pages;

use App\Filament\Admin\Resources\UtilityMeters\UtilityMeterResource;
use App\Filament\Imports\MeterReadingImporter;
use App\Support\Imports;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListUtilityMeters extends ListRecords
{
    protected static string $resource = UtilityMeterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportAction::make()
                ->importer(MeterReadingImporter::class)
                ->label(__('admin.actions.import'))
                ->icon('heroicon-o-arrow-up-tray')
                // Import is admin-only (FR-USR-02) and is NOT a flavour of create — one wrong CSV
                // column rewrites the whole register. Gated in both places, through the one
                // registry, so the import buttons cannot drift apart.
                ->visible(fn () => Imports::allowed())
                ->authorize(fn () => Imports::allowed()),
            CreateAction::make()
                ->visible(fn () => UtilityMeterResource::canCreate()),
        ];
    }

    /** A faulty meter stops the recharge billing for whoever sits behind it. */
    public function getTabs(): array
    {
        return StatusTabs::build(UtilityMeterResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'active' => ['label' => __('admin.statuses.meter.active'), 'statuses' => ['active']],
            'faulty' => ['label' => __('admin.statuses.meter.faulty'), 'statuses' => ['faulty'], 'badge' => true, 'color' => 'danger'],
            'inactive' => ['label' => __('admin.statuses.meter.inactive'), 'statuses' => ['inactive']],
        ]);
    }
}
