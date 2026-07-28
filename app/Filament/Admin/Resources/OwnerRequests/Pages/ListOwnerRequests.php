<?php

namespace App\Filament\Admin\Resources\OwnerRequests\Pages;

use App\Filament\Admin\Resources\OwnerRequests\OwnerRequestResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOwnerRequests extends ListRecords
{
    protected static string $resource = OwnerRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    /** What the owner is still waiting on the operator to answer. */
    public function getTabs(): array
    {
        return StatusTabs::build(OwnerRequestResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'open' => ['label' => __('admin.tabs.open'), 'statuses' => ['open'], 'badge' => true, 'color' => 'warning'],
            'in_progress' => ['label' => __('admin.tabs.in_progress'), 'statuses' => ['in_progress'], 'badge' => true, 'color' => 'info'],
            'resolved' => ['label' => __('admin.tabs.resolved'), 'statuses' => ['resolved']],
            'closed' => ['label' => __('admin.tabs.closed'), 'statuses' => ['closed', 'cancelled']],
        ]);
    }
}
