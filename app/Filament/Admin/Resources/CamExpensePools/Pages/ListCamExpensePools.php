<?php

namespace App\Filament\Admin\Resources\CamExpensePools\Pages;

use App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCamExpensePools extends ListRecords
{
    protected static string $resource = CamExpensePoolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn () => CamExpensePoolResource::canCreate()),
        ];
    }

    /** The annual CAM cycle, one tab per stage. */
    public function getTabs(): array
    {
        return StatusTabs::build(CamExpensePoolResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'draft' => ['label' => __('admin.tabs.draft'), 'statuses' => ['draft'], 'badge' => true, 'color' => 'gray'],
            'reconciling' => ['label' => __('admin.tabs.reconciling'), 'statuses' => ['reconciling'], 'badge' => true, 'color' => 'warning'],
            'reconciled' => ['label' => __('admin.tabs.reconciled'), 'statuses' => ['reconciled']],
            'closed' => ['label' => __('admin.tabs.closed'), 'statuses' => ['closed']],
        ]);
    }
}
