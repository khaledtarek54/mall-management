<?php

namespace App\Filament\Admin\Resources\Payments\Pages;

use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /** Captured is the money that actually landed; failed/bounced is the exception queue. */
    public function getTabs(): array
    {
        return StatusTabs::build(PaymentResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'in_progress' => ['label' => __('admin.tabs.in_progress'), 'statuses' => ['initiated', 'authorized'], 'badge' => true, 'color' => 'warning'],
            'captured' => ['label' => __('admin.statuses.payment.captured'), 'statuses' => ['captured', 'reconciled', 'settled']],
            'failed' => ['label' => __('admin.tabs.failed'), 'statuses' => ['failed', 'bounced'], 'badge' => true, 'color' => 'danger'],
            'refunded' => ['label' => __('admin.statuses.payment.refunded'), 'statuses' => ['refunded']],
        ]);
    }
}
