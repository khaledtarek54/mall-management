<?php

namespace App\Filament\Admin\Resources\BankAccounts\Tables;

use App\Filament\Admin\Resources\BankAccounts\BankAccountResource;
use App\Models\BankAccount;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
                    ->sortable()
                    // The number is masked in the list: recognisable, not exposed on a shared screen.
                    ->description(fn (BankAccount $record) => trim(
                        ($record->bank_name ?? '').' '.($record->maskedNumber() ?? '')
                    ) ?: null),
                TextColumn::make('asset.name')
                    ->label(__('admin.fields.property'))
                    ->badge()
                    ->sortable()
                    ->color('gray'),
                TextColumn::make('ledgerAccount.code')
                    ->label(__('admin.fields.ledger_account'))
                    ->placeholder(__('admin.fields.bank_no_ledger_account'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->sortable(),
                // Not toggled off by default: which account is the DEFAULT is the operative fact on
                // this register now — it is what every money form fills itself from — so a list that
                // hides it makes the defaulting look like it is not happening.
                TextColumn::make('purpose')
                    ->label(__('admin.fields.purpose'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => __('admin.enums.bank_account_purpose.'.$state))
                    ->color(fn (?string $state) => match ($state) {
                        BankAccount::PURPOSE_DEPOSITS => 'warning',
                        BankAccount::PURPOSE_PAYROLL => 'info',
                        default => 'gray',
                    })
                    ->description(fn (BankAccount $record) => $record->is_default
                        ? __('admin.fields.is_default')
                        : null)
                    ->sortable(),
                TextColumn::make('currency')
                    ->label(__('admin.fields.currency'))
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label(__('admin.fields.is_active'))
                    ->sortable()
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('purpose')
                    ->label(__('admin.fields.purpose'))
                    ->options(fn () => __('admin.enums.bank_account_purpose')),
                TernaryFilter::make('is_default')->label(__('admin.fields.is_default')),
                TernaryFilter::make('is_active')->label(__('admin.fields.is_active')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                // Read it without opening the write form — less friction, and no write surface for
                // a view-only role. The modal schema is the resource's own form rendered disabled,
                // so it cannot drift from the fields that exist.
                ViewAction::make(),
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
