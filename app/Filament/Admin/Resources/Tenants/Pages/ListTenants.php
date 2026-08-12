<?php

namespace App\Filament\Admin\Resources\Tenants\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Imports\TenantImporter;
use App\Support\Imports;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListTenants extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(TenantResource::class),
            ImportAction::make()
                ->importer(TenantImporter::class)
                ->label(__('admin.actions.import'))
                ->icon('heroicon-o-arrow-up-tray')
                            // Bulk import writes tenant records — gate server-side (was ungated).
                ->visible(fn () => Imports::allowed())
                ->authorize(fn () => Imports::allowed()),
            CreateAction::make(),
        ];
    }

    /** Blacklisted is its own tab: it is a credit decision, not a dormant record. */
    public function getTabs(): array
    {
        return StatusTabs::build(TenantResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'active' => ['label' => __('admin.statuses.tenant.active'), 'statuses' => ['active']],
            'inactive' => ['label' => __('admin.statuses.tenant.inactive'), 'statuses' => ['inactive']],
            'blacklisted' => ['label' => __('admin.statuses.tenant.blacklisted'), 'statuses' => ['blacklisted'], 'badge' => true, 'color' => 'danger'],
        ]);
    }
}
