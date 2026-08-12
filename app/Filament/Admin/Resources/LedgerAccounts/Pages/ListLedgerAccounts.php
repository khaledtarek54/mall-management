<?php

namespace App\Filament\Admin\Resources\LedgerAccounts\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\LedgerAccounts\LedgerAccountResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLedgerAccounts extends ListRecords
{
    protected static string $resource = LedgerAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }

    /**
     * The chart of accounts split the way an accountant reads it — balance-sheet
     * accounts first, then P&L. Tabs on `type`, not `status`.
     */
    public function getTabs(): array
    {
        return StatusTabs::build(LedgerAccountResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'asset' => ['label' => __('admin.enums.ledger_account_type.asset'), 'statuses' => ['asset']],
            'liability' => ['label' => __('admin.enums.ledger_account_type.liability'), 'statuses' => ['liability']],
            'equity' => ['label' => __('admin.enums.ledger_account_type.equity'), 'statuses' => ['equity']],
            'revenue' => ['label' => __('admin.enums.ledger_account_type.revenue'), 'statuses' => ['revenue']],
            'expense' => ['label' => __('admin.enums.ledger_account_type.expense'), 'statuses' => ['expense']],
        ], column: 'type');
    }
}
