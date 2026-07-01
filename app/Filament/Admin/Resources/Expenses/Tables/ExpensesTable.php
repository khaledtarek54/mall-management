<?php

namespace App\Filament\Admin\Resources\Expenses\Tables;

use App\Filament\Admin\Resources\Expenses\ExpenseResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label(__('admin.fields.expense_number'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('asset.name')
                    ->label(__('admin.fields.property'))
                    ->placeholder(__('admin.fields.property_consolidated'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('category')
                    ->label(__('admin.fields.category'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.vendor_bill_category.{$state}"))
                    ->color('gray'),
                TextColumn::make('paid_from')
                    ->label(__('admin.fields.paid_from'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.expense_paid_from.{$state}")),
                TextColumn::make('expense_date')
                    ->label(__('admin.fields.expense_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('amount')
                    ->label(__('admin.fields.amount'))
                    ->money('EGP')
                    ->alignRight(),
                TextColumn::make('vat_amount')
                    ->label(__('admin.fields.vat_amount'))
                    ->money('EGP')
                    ->alignRight()
                    ->toggleable(),
                TextColumn::make('total')
                    ->label(__('admin.fields.total'))
                    ->money('EGP')
                    ->weight('bold')
                    ->alignRight(),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.expense.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'recorded' => 'success',
                        'cancelled' => 'gray',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.expense')),
                SelectFilter::make('category')
                    ->label(__('admin.fields.category'))
                    ->options(fn () => __('admin.enums.vendor_bill_category')),
                SelectFilter::make('paid_from')
                    ->label(__('admin.fields.paid_from'))
                    ->options(fn () => __('admin.enums.expense_paid_from')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn ($record) => ExpenseResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => ExpenseResource::canDeleteAny()),
                ]),
            ])
            ->defaultSort('expense_date', 'desc')
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateHeading(__('admin.empty.expenses.heading'))
            ->emptyStateDescription(__('admin.empty.expenses.description'));
    }
}
