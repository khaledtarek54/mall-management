<?php

namespace App\Filament\Admin\Resources\Disbursements\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Disbursements\DisbursementResource;
use App\Support\StatusTabs;
use Filament\Resources\Pages\ListRecords;

class ListDisbursements extends ListRecords
{
    protected static string $resource = DisbursementResource::class;

    // Disbursements are scheduled from a finalised owner statement (the run's "Schedule payout"
    // action), never created blank here — so no create header action.
    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()), ];
    }

    /** Owner payouts, in the order they move: scheduled → approved → paid. */
    public function getTabs(): array
    {
        return StatusTabs::build(DisbursementResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'scheduled' => ['label' => __('admin.disbursements.statuses.scheduled'), 'statuses' => ['scheduled'], 'badge' => true, 'color' => 'warning'],
            'approved' => ['label' => __('admin.disbursements.statuses.approved'), 'statuses' => ['approved'], 'badge' => true, 'color' => 'info'],
            'paid' => ['label' => __('admin.disbursements.statuses.paid'), 'statuses' => ['paid']],
            'cancelled' => ['label' => __('admin.tabs.cancelled'), 'statuses' => ['cancelled']],
        ]);
    }
}
