<?php

namespace App\Filament\Admin\Resources\Expenses\Tables;

use App\Filament\Actions\LedgerEntryAction;
use App\Filament\Admin\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Support\Filament\BankAccountColumn;
use App\Support\Filament\BankAccountFilter;
use App\Support\Filament\DateRangeFilter;
use App\Support\Filament\TableGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Summarizers\Sum;
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
                    ->formatStateUsing(fn (?string $state) => ExpenseCategory::labelFor($state))
                    ->color('gray'),
                // Fixed vs variable (FR-FIN-02) — derived from the category via App\Support\CostNature,
                // so the register agrees with the weekly-spend report. Toggleable (off by default).
                TextColumn::make('cost_nature')
                    ->label(__('admin.fields.cost_nature'))
                    ->badge()
                    ->getStateUsing(fn (Expense $record): string => $record->costNature())
                    ->formatStateUsing(fn (string $state) => __("admin.enums.cost_nature.{$state}"))
                    ->color(fn (string $state) => $state === 'fixed' ? 'info' : 'warning')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('paid_from')
                    ->label(__('admin.fields.paid_from'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => PaymentMethod::labelFor($state, 'admin.enums.expense_paid_from')),
                TextColumn::make('expense_date')
                    ->label(__('admin.fields.expense_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('amount')
                    ->label(__('admin.fields.amount'))
                    ->money('EGP')
                    ->alignRight()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('vat_amount')
                    ->label(__('admin.fields.vat_amount'))
                    ->money('EGP')
                    ->alignRight()
                    ->toggleable()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('total')
                    ->label(__('admin.fields.total'))
                    ->money('EGP')
                    ->weight('bold')
                    ->alignRight()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.expense.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'recorded' => 'success',
                        'cancelled' => 'gray',
                        default => 'gray',
                    }),
                BankAccountColumn::make(),
            ])
            ->filters([
                // The AP register's twin (SW-083) — same question, same missing control.
                DateRangeFilter::make('expense_date', __('admin.fields.expense_date')),
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.expense')),
                SelectFilter::make('category')
                    ->label(__('admin.fields.category'))
                    ->options(fn () => ExpenseCategory::filterOptions()),
                SelectFilter::make('cost_nature')
                    ->label(__('admin.fields.cost_nature'))
                    ->options(fn () => __('admin.enums.cost_nature'))
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->ofNature($data['value'])
                        : $query),
                SelectFilter::make('paid_from')
                    ->label(__('admin.fields.paid_from'))
                    ->options(fn () => PaymentMethod::filterOptionsFor('expenses.paid_from', 'admin.enums.expense_paid_from')),
                TrashedFilter::make(),
                BankAccountFilter::make(),
            ])
            // Category is the axis the owner's cost report is built on.
            ->groups([
                TableGroup::byColumn($table, 'category'),
            ])
            ->recordActions([
                LedgerEntryAction::make(),
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => ExpenseResource::canView($record))
                    ->authorize(fn ($record) => ExpenseResource::canView($record)),
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
