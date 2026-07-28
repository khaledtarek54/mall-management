<?php

namespace App\Filament\Admin\Resources\Vendors\Pages;

use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVendors extends ListRecords
{
    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    /** Blacklisted vendors must be one glance away — they may not be awarded work. */
    public function getTabs(): array
    {
        return StatusTabs::build(VendorResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'active' => ['label' => __('admin.statuses.vendor.active'), 'statuses' => ['active']],
            'inactive' => ['label' => __('admin.statuses.vendor.inactive'), 'statuses' => ['inactive']],
            'blacklisted' => ['label' => __('admin.statuses.vendor.blacklisted'), 'statuses' => ['blacklisted'], 'badge' => true, 'color' => 'danger'],
        ]);
    }
}
