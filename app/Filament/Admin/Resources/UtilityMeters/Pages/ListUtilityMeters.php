<?php

namespace App\Filament\Admin\Resources\UtilityMeters\Pages;

use App\Filament\Admin\Resources\UtilityMeters\UtilityMeterResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUtilityMeters extends ListRecords
{
    protected static string $resource = UtilityMeterResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
