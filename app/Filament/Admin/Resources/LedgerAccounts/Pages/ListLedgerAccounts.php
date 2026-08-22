<?php

namespace App\Filament\Admin\Resources\LedgerAccounts\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\LedgerAccounts\LedgerAccountResource;
use App\Filament\Imports\LedgerAccountImporter;
use App\Support\Imports;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListLedgerAccounts extends ListRecords
{
    protected static string $resource = LedgerAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            // Adopting the accountant's own chart, which on a first deploy otherwise means typing a
            // few hundred accounts into a form (EG-28). Gated on `Imports::allowed()` like every
            // other import: the FRD restricts import to admins, and one wrong column here
            // re-natures accounts the whole ledger posts through.
            ImportAction::make()
                ->importer(LedgerAccountImporter::class)
                ->label(__('admin.actions.import'))
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => Imports::allowed())
                ->authorize(fn (): bool => Imports::allowed()),
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
