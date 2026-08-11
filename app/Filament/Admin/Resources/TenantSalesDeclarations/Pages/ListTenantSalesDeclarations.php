<?php

namespace App\Filament\Admin\Resources\TenantSalesDeclarations\Pages;

use App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTenantSalesDeclarations extends ListRecords
{
    protected static string $resource = TenantSalesDeclarationResource::class;

    protected function getHeaderActions(): array
    {
        return [
                        \App\Filament\Actions\GuideAction::for(TenantSalesDeclarationResource::class),
CreateAction::make()
                ->visible(fn () => TenantSalesDeclarationResource::canCreate()),
        ];
    }

    /** Submitted = declared by the tenant, not yet reviewed + locked by the operator. */
    public function getTabs(): array
    {
        return StatusTabs::build(TenantSalesDeclarationResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'needs_review' => ['label' => __('admin.tabs.needs_review'), 'statuses' => ['submitted'], 'badge' => true, 'color' => 'warning'],
            'locked' => ['label' => __('admin.tabs.locked'), 'statuses' => ['locked']],
            'disputed' => ['label' => __('admin.tabs.disputed'), 'statuses' => ['disputed'], 'badge' => true, 'color' => 'danger'],
        ]);
    }
}
