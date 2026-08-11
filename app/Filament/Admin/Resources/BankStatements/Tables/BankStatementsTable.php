<?php

namespace App\Filament\Admin\Resources\BankStatements\Tables;

use App\Filament\Admin\Resources\BankStatements\BankStatementResource;
use App\Models\BankStatement;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BankStatementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->searchable(false)
            ->modifyQueryUsing(fn ($query) => $query->with('bankAccount.asset')->withCount('lines'))
            ->columns([
                TextColumn::make('bankAccount.name')
                    ->label(__('admin.resources.bank_account.singular'))
                    ->weight('bold')
                    ->description(function (BankStatement $record) {
                        $account = $record->getRelationValue('bankAccount');

                        $asset = $account instanceof \App\Models\BankAccount ? $account->getRelationValue('asset') : null;

                        return $asset instanceof \App\Models\Asset ? $asset->name : null;
                    }),
                TextColumn::make('period')
                    ->label(__('admin.fields.period'))
                    ->getStateUsing(fn (BankStatement $record) => $record->label()),
                TextColumn::make('lines_count')
                    ->label(__('admin.fields.statement_lines'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('closing_balance')
                    ->label(__('admin.fields.closing_balance'))
                    ->money('EGP')
                    ->alignRight(),
                // The cheapest signal that a file was truncated, half-mapped, or had its signs read
                // backwards: does the BANK's own arithmetic hold? It says nothing about the books,
                // and everything about whether this statement was ingested faithfully — which is the
                // precondition for matching anything against it.
                // The ageing, where it is actually seen: an operator scanning the statement list
                // should not have to open each one to find the questions nobody has asked.
                TextColumn::make('aged_unmatched')
                    ->label(__('admin.bank.aged_unmatched'))
                    ->badge()
                    ->getStateUsing(function (BankStatement $record) {
                        $count = $record->agedUnmatchedCount();

                        return $count > 0 ? __('admin.bank.aged_count', ['count' => $count]) : null;
                    })
                    ->color('danger')
                    ->placeholder('—')
                    ->tooltip(__('admin.bank.aged_unmatched_hint')),
                TextColumn::make('consistency')
                    ->label(__('admin.fields.statement_consistent'))
                    ->badge()
                    ->getStateUsing(fn (BankStatement $record) => $record->isSelfConsistent()
                        ? __('admin.bank.consistent')
                        : __('admin.bank.inconsistent'))
                    ->color(fn (BankStatement $record) => $record->isSelfConsistent() ? 'success' : 'danger')
                    ->tooltip(__('admin.helpers.statement_consistent')),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (BankStatement $record) => BankStatementResource::canEdit($record)),
            ])
            ->defaultSort('period_end', 'desc')
            ->emptyStateIcon('heroicon-o-document-check')
            ->emptyStateHeading(__('admin.empty.bank_statements.heading'))
            ->emptyStateDescription(__('admin.empty.bank_statements.description'));
    }
}
