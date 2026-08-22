<?php

namespace App\Filament\Admin\Resources\DepositTransactions\Tables;

use App\Filament\Admin\Resources\DepositTransactions\DepositTransactionResource;
use App\Models\PaymentMethod;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class DepositTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('lease.tenant', 'asset'))
            ->columns([
                TextColumn::make('number')
                    ->label(__('admin.fields.deposit_number'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('type')
                    ->label(__('admin.filters.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.deposit_type.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'receipt' => 'success',
                        'refund' => 'warning',
                        'forfeit' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('lease.tenant.name')
                    ->label(__('admin.filters.tenant'))
                    ->placeholder('—'),
                TextColumn::make('asset.name')
                    ->label(__('admin.fields.property'))
                    ->placeholder(__('admin.fields.property_consolidated'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('transaction_date')
                    ->label(__('admin.fields.transaction_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('amount')
                    ->label(__('admin.fields.amount'))
                    ->money('EGP')
                    ->alignRight()
                    ->weight('bold')
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('method')
                    ->label(__('admin.fields.method'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => PaymentMethod::labelFor($state, 'admin.enums.expense_paid_from')),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.deposit_transaction.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'recorded' => 'success',
                        'cancelled' => 'gray',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.filters.type'))
                    ->options(fn () => __('admin.enums.deposit_type')),
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.deposit_transaction')),
                SelectFilter::make('method')
                    ->label(__('admin.fields.method'))
                    // filterOptionsFor(), not the FORM set: a filter is about what is already recorded, so
                    // a retired rail must still find the deposits taken on it.
                    ->options(fn () => PaymentMethod::filterOptionsFor('deposit_transactions.method', 'admin.enums.expense_paid_from')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => DepositTransactionResource::canView($record))
                    ->authorize(fn ($record) => DepositTransactionResource::canView($record)),
                EditAction::make()
                    ->visible(fn ($record) => DepositTransactionResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => DepositTransactionResource::canDeleteAny()),
                ]),
            ])
            ->defaultSort('transaction_date', 'desc')
            ->emptyStateIcon('heroicon-o-shield-check')
            ->emptyStateHeading(__('admin.empty.deposit_transactions.heading'))
            ->emptyStateDescription(__('admin.empty.deposit_transactions.description'));
    }
}
