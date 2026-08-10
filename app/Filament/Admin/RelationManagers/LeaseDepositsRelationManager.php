<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\Resources\DepositTransactions\DepositTransactionResource;
use App\Models\DepositTransaction;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The security deposit held against this lease, and what has happened to it.
 *
 * "Have they paid the deposit, and how much of it is still ours?" is a lease question asked at
 * signing, at renewal and again at move-out — and it was answered in the deposit register, filtered
 * by hand. The lease carried `security_deposit` (what was AGREED) and `security_deposit_received`
 * (a yes/no), neither of which tells you what was actually received, refunded, forfeited or netted
 * against arrears.
 *
 * Read-only. A deposit movement is money: it is recorded through its own resource, where the GL
 * posting, the posting-date guard and the netting rules live. A create button here would be a second
 * way to move money, thinner than the first.
 */
class LeaseDepositsRelationManager extends RelationManager
{
    protected static string $relationship = 'deposits';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('admin.navigation.deposit_transactions');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label(__('admin.fields.deposit_number'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->searchable(),

                TextColumn::make('transaction_date')
                    ->label(__('admin.fields.transaction_date'))
                    ->date('d/m/Y')
                    ->sortable(),

                // Receipt / refund / forfeit — the movement, not a running total.
                // A single "balance" column here would be a second truth about the same money.
                TextColumn::make('type')
                    ->label(__('admin.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.deposit_type.{$state}"))
                    ->color(fn (string $state) => $state === 'receipt' ? 'success' : 'gray'),

                TextColumn::make('amount')
                    ->label(__('admin.fields.amount'))
                    ->money('EGP'),

                TextColumn::make('method')
                    ->label(__('admin.fields.method'))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('status')
                    ->label(__('admin.filters.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.deposit_transaction.{$state}")),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('admin.actions.open'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (DepositTransaction $record): string => DepositTransactionResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (DepositTransaction $record): bool => DepositTransactionResource::canEdit($record)),
            ])
            ->defaultSort('transaction_date', 'desc')
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateHeading(__('admin.lease_deposits.empty_heading'))
            ->emptyStateDescription(__('admin.lease_deposits.empty_description'));
    }
}
