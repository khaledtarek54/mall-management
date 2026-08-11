<?php

namespace App\Filament\Admin\Resources\BankStatements\Pages;

use App\Filament\Admin\Resources\BankStatements\BankStatementResource;
use App\Models\BankStatement;
use App\Services\Banking\ReconcileBankStatementService;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\EditRecord;

class EditBankStatement extends EditRecord
{
    protected static string $resource = BankStatementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reconciliation')
                ->label(__('admin.actions.reconciliation'))
                ->icon('heroicon-o-scale')
                ->color('gray')
                ->modalHeading(__('admin.bank.reconciliation_heading'))
                ->modalSubmitAction(false)
                ->schema(fn (BankStatement $record) => self::summary($record)),
        ];
    }

    /**
     * The reconciliation, as the one identity it is:
     *
     *     ledger = statement closing + unmatched book postings − unmatched statement lines
     *
     * Shown as its terms rather than a verdict, because "not reconciled" is useless on its own —
     * what an operator needs is which of the two explanations is missing, and by how much.
     *
     * @return array<int, TextEntry>
     */
    private static function summary(BankStatement $record): array
    {
        $r = app(ReconcileBankStatementService::class)->for($record);

        if (! $r['mapped']) {
            // Never a zeroed report: zeroes read as "reconciled", which is the one thing this must
            // not say by accident.
            return [
                TextEntry::make('unmapped')
                    ->label(__('admin.bank.reconciliation_state'))
                    ->state(__('admin.bank.unmapped_account'))
                    ->color('danger'),
            ];
        }

        $money = fn (float $v) => number_format($v, 2).' EGP';

        return [
            TextEntry::make('state')
                ->label(__('admin.bank.reconciliation_state'))
                ->state($r['reconciled'] ? __('admin.bank.reconciled') : __('admin.bank.not_reconciled'))
                ->badge()
                ->color($r['reconciled'] ? 'success' : 'danger'),

            TextEntry::make('statement_closing')
                ->label(__('admin.fields.closing_balance'))
                ->state($money($r['statement_closing'])),

            TextEntry::make('unmatched_book')
                ->label(__('admin.bank.unmatched_book'))
                ->state($money($r['unmatched_book_total']))
                ->helperText(__('admin.bank.unmatched_book_hint', ['count' => $r['unmatched_book_count']])),

            TextEntry::make('unmatched_statement')
                ->label(__('admin.bank.unmatched_statement'))
                ->state($money($r['unmatched_statement_total']))
                ->helperText(__('admin.bank.unmatched_statement_hint', ['count' => $r['unmatched_statement_count']])),

            TextEntry::make('ledger')
                ->label(__('admin.bank.ledger_balance'))
                ->state($money($r['ledger_balance']))
                ->helperText(__('admin.bank.expected_ledger', ['amount' => $money($r['expected_ledger'])])),

            TextEntry::make('difference')
                ->label(__('admin.bank.difference'))
                ->state($money($r['difference']))
                ->color($r['reconciled'] ? 'success' : 'danger')
                ->helperText($r['reconciled'] ? null : __('admin.bank.difference_hint')),
        ];
    }
}
