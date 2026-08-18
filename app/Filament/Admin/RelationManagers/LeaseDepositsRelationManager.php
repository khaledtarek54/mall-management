<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\Resources\DepositTransactions\DepositTransactionResource;
use App\Models\DepositTransaction;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The security deposit held against this lease, and what has happened to it.
 *
 * "Have they paid the deposit, and how much of it is still ours?" is a lease question asked at
 * signing, at renewal and again at move-out — and it was answered in the deposit register, filtered
 * by hand. The lease carried `security_deposit` (what was AGREED) and `security_deposit_received`
 * (a yes/no), neither of which tells you what was actually received, refunded, forfeited or netted
 * against arrears.
 *
 * Read-only, and the movement is recorded from the lease's own **Record deposit movement** action
 * rather than from a button on this table — one place to act, beside every other act on a tenancy.
 *
 * This used to say a create button here "would be a second way to move money, thinner than the
 * first", and that reasoning was wrong on the facts (corrected 2026-08-18): every guard is on the
 * MODEL — `GuardsPostingDate`, `AllocatesDocumentNumber`, the ValueSets listener, the GL registry —
 * so any surface that creates a `DepositTransaction` inherits all of them. The register remains the
 * portfolio view: what the property holds in total, which is a balance-sheet question and not a
 * lease one.
 */
class LeaseDepositsRelationManager extends RelationManager
{
    protected static string $relationship = 'deposits';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
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
