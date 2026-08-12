<?php

namespace App\Filament\Admin\Resources\PurchaseRequests\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseRequests extends ListRecords
{
    protected static string $resource = PurchaseRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            CreateAction::make()->visible(fn () => PurchaseRequestResource::canCreate()),
        ];
    }

    /** Procurement pipeline, in the order a request actually travels. */
    public function getTabs(): array
    {
        return StatusTabs::build(PurchaseRequestResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'pending_approval' => ['label' => __('admin.tabs.pending_approval'), 'statuses' => ['requested'], 'badge' => true, 'color' => 'warning'],
            'approved' => ['label' => __('admin.tabs.approved'), 'statuses' => ['approved'], 'badge' => true, 'color' => 'info'],
            'ordered' => ['label' => __('admin.tabs.ordered'), 'statuses' => ['ordered'], 'badge' => true, 'color' => 'info'],
            'received' => ['label' => __('admin.tabs.received'), 'statuses' => ['received']],
            'closed' => ['label' => __('admin.tabs.closed'), 'statuses' => ['rejected', 'cancelled']],
        ]);
    }
}
