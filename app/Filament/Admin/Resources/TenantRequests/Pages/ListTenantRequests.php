<?php

namespace App\Filament\Admin\Resources\TenantRequests\Pages;

use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTenantRequests extends ListRecords
{
    protected static string $resource = TenantRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn () => TenantRequestResource::canCreate()),
        ];
    }

    /**
     * Open = everything still on the operator's plate. Awaiting-tenant is split
     * out because it is blocked on someone else — it should not sit in the same
     * pile as work the team can actually move.
     */
    public function getTabs(): array
    {
        return StatusTabs::build(TenantRequestResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'open' => ['label' => __('admin.tabs.open'), 'statuses' => ['submitted', 'acknowledged', 'in_progress'], 'badge' => true, 'color' => 'warning'],
            'awaiting_tenant' => ['label' => __('admin.tabs.awaiting_tenant'), 'statuses' => ['awaiting_tenant'], 'badge' => true, 'color' => 'info'],
            'resolved' => ['label' => __('admin.tabs.resolved'), 'statuses' => ['resolved']],
            'closed' => ['label' => __('admin.tabs.closed'), 'statuses' => ['closed', 'cancelled']],
        ]);
    }
}
