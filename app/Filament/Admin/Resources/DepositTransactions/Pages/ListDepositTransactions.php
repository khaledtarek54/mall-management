<?php

namespace App\Filament\Admin\Resources\DepositTransactions\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\DepositTransactions\DepositTransactionResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDepositTransactions extends ListRecords
{
    protected static string $resource = DepositTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }

    /** Deposits split by what happened to the money. Tabs on `type`, not `status`. */
    public function getTabs(): array
    {
        return StatusTabs::build(DepositTransactionResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'receipt' => ['label' => __('admin.enums.deposit_type.receipt'), 'statuses' => ['receipt']],
            'refund' => ['label' => __('admin.enums.deposit_type.refund'), 'statuses' => ['refund']],
            'forfeit' => ['label' => __('admin.enums.deposit_type.forfeit'), 'statuses' => ['forfeit']],
        ], column: 'type');
    }
}
