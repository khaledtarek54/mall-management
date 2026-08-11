<?php

namespace App\Filament\Admin\Resources\Vendors\Pages;

use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Filament\Imports\VendorImporter;
use App\Support\Imports;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListVendors extends ListRecords
{
    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportAction::make()
                ->importer(VendorImporter::class)
                ->label(__('admin.actions.import'))
                ->icon('heroicon-o-arrow-up-tray')
                // Import is admin-only (FR-USR-02) and is NOT a flavour of create — one wrong CSV
                // column rewrites the whole supplier register. Gated in both places, through the
                // one registry, so the four import buttons cannot drift apart.
                ->visible(fn () => Imports::allowed())
                ->authorize(fn () => Imports::allowed()),
            CreateAction::make(),
        ];
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
