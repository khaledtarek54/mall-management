<?php

namespace App\Filament\Admin\Resources\VendorBills\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Filament\Admin\Resources\VendorBills\VendorBillResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVendorBills extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = VendorBillResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }

    /** AP worklist: what needs approving, then what needs paying. */
    public function getTabs(): array
    {
        return StatusTabs::build(VendorBillResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'draft' => ['label' => __('admin.tabs.draft'), 'statuses' => ['draft'], 'badge' => true, 'color' => 'gray'],
            'unpaid' => ['label' => __('admin.tabs.unpaid'), 'statuses' => ['approved', 'partially_paid'], 'badge' => true, 'color' => 'warning'],
            'paid' => ['label' => __('admin.tabs.paid'), 'statuses' => ['paid']],
            'cancelled' => ['label' => __('admin.tabs.cancelled'), 'statuses' => ['cancelled']],
        ]);
    }
}
