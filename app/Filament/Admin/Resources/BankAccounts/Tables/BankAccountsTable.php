<?php

namespace App\Filament\Admin\Resources\BankAccounts\Tables;

use App\Filament\Admin\Resources\BankAccounts\BankAccountResource;
use App\Models\BankAccount;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class BankAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('asset', 'ledgerAccount'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.fields.bank_account_name'))
                    ->weight('bold')
                    ->searchable(['search_text'])
                    // The number is masked in the list: recognisable, not exposed on a shared screen.
                    ->description(fn (BankAccount $record) => trim(
                        ($record->bank_name ?? '').' '.($record->maskedNumber() ?? '')
                    ) ?: null),
                TextColumn::make('asset.name')
                    ->label(__('admin.fields.property'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('ledgerAccount.code')
                    ->label(__('admin.fields.ledger_account'))
                    ->placeholder(__('admin.fields.bank_no_ledger_account'))
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('currency')
                    ->label(__('admin.fields.currency'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label(__('admin.fields.is_active'))
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('admin.fields.is_active')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (BankAccount $record) => BankAccountResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->visible(fn () => BankAccountResource::canDeleteAny()),
                ]),
            ])
            ->defaultSort('name')
            ->emptyStateIcon('heroicon-o-building-library')
            ->emptyStateHeading(__('admin.empty.bank_accounts.heading'))
            ->emptyStateDescription(__('admin.empty.bank_accounts.description'));
    }
}
