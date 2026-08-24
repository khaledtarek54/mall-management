<?php

namespace App\Filament\Admin\Resources\Payrolls\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Filament\Admin\Resources\Payrolls\PayrollResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPayrolls extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = PayrollResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }

    /** Draft runs are the ones still awaiting approval before they pay out. */
    public function getTabs(): array
    {
        return StatusTabs::build(PayrollResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'draft' => ['label' => __('admin.tabs.draft'), 'statuses' => ['draft'], 'badge' => true, 'color' => 'warning'],
            'approved' => ['label' => __('admin.tabs.approved'), 'statuses' => ['approved']],
            'cancelled' => ['label' => __('admin.tabs.cancelled'), 'statuses' => ['cancelled']],
        ]);
    }
}
