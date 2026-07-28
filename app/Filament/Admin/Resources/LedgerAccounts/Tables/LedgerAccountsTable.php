<?php

namespace App\Filament\Admin\Resources\LedgerAccounts\Tables;

use App\Filament\Admin\Resources\LedgerAccounts\LedgerAccountResource;
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

class LedgerAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.tables.ledger_account.code'))
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('name_ar')
                    ->label(__('admin.tables.ledger_account.name_ar'))
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('name_en')
                    ->label(__('admin.tables.ledger_account.name_en'))
                    ->searchable()
                    ->color('gray'),
                TextColumn::make('type')
                    ->label(__('admin.fields.account_type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.ledger_account_type.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'asset' => 'info',
                        'liability' => 'warning',
                        'equity' => 'primary',
                        'revenue' => 'success',
                        'expense' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('normal_balance')
                    ->label(__('admin.fields.normal_balance'))
                    ->formatStateUsing(fn (string $state) => __("admin.enums.normal_balance.{$state}"))
                    ->color('gray')
                    ->toggleable(),
                IconColumn::make('is_postable')
                    ->label(__('admin.fields.is_postable'))
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label(__('admin.fields.is_active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.fields.account_type'))
                    ->options(fn () => __('admin.enums.ledger_account_type')),
                TernaryFilter::make('is_postable')
                    ->label(__('admin.fields.is_postable')),
                TernaryFilter::make('is_active')
                    ->label(__('admin.fields.is_active')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => LedgerAccountResource::canView($record))
                    ->authorize(fn ($record) => LedgerAccountResource::canView($record)),
                EditAction::make()
                    ->visible(fn ($record) => LedgerAccountResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => LedgerAccountResource::canDeleteAny()),
                ]),
            ])
            ->defaultSort('code')
            ->paginated([25, 50, 100, 'all'])
            ->emptyStateIcon('heroicon-o-rectangle-stack')
            ->emptyStateHeading(__('admin.empty.ledger_accounts.heading'))
            ->emptyStateDescription(__('admin.empty.ledger_accounts.description'));
    }
}
