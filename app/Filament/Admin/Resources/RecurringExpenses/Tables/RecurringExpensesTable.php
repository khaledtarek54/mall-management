<?php

namespace App\Filament\Admin\Resources\RecurringExpenses\Tables;

use App\Models\ExpenseCategory;
use App\Models\RecurringExpense;
use Carbon\CarbonImmutable;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RecurringExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('description')
            ->columns([
                TextColumn::make('description')
                    ->label(__('admin.fields.description'))
                    ->searchable()
                    ->wrap(),

                TextColumn::make('category')
                    ->label(__('admin.fields.category'))
                    ->formatStateUsing(fn (string $state): string => ExpenseCategory::labelFor($state))
                    ->badge()
                    ->color('gray'),

                // Which of the two things this schedule raises. A blank vendor is not missing
                // data — it is the statement that the cost has no creditor and books straight to
                // the ledger, so the column says which, rather than showing an empty cell.
                TextColumn::make('vendor.name')
                    ->label(__('admin.fields.vendor'))
                    ->placeholder(__('admin.recurring_expenses.no_vendor'))
                    ->description(fn (RecurringExpense $record): string => $record->billsAVendor()
                        ? __('admin.recurring_expenses.raises_bill')
                        : __('admin.recurring_expenses.raises_expense'))
                    ->searchable(),

                TextColumn::make('amount')
                    ->label(__('admin.fields.amount'))
                    ->money('EGP')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('frequency')
                    ->label(__('admin.fields.frequency'))
                    ->formatStateUsing(fn (string $state): string => __("admin.recurring_expenses.frequencies.{$state}"))
                    ->badge(),

                // What an operator actually opens this screen to check: when does the next one book.
                // Derived, never stored — it is a function of TODAY, and a stored copy would go
                // stale on a day when nothing happened (the `ProjectedState` rule).
                TextColumn::make('next_due')
                    ->label(__('admin.recurring_expenses.fields.next_due'))
                    ->state(fn (RecurringExpense $record): ?string => $record
                        ->nextDueOn(CarbonImmutable::now()->addYears(2))?->toDateString())
                    ->placeholder(__('admin.recurring_expenses.fields.nothing_due'))
                    ->description(fn (RecurringExpense $record): ?string => $record->last_generated_on
                        ? __('admin.recurring_expenses.fields.last_booked', ['date' => $record->last_generated_on->toDateString()])
                        : null),

                TextColumn::make('ends_on')
                    ->label(__('admin.fields.end_date'))
                    ->date()
                    ->placeholder(__('admin.recurring_expenses.fields.no_end'))
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label(__('admin.fields.is_active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('frequency')
                    ->label(__('admin.fields.frequency'))
                    ->options(fn (): array => collect(RecurringExpense::FREQUENCIES)
                        ->mapWithKeys(fn (string $f): array => [$f => __("admin.recurring_expenses.frequencies.{$f}")])
                        ->all()),

                SelectFilter::make('category')
                    ->label(__('admin.fields.category'))
                    ->options(fn () => ExpenseCategory::options()),

                TernaryFilter::make('is_active')->label(__('admin.fields.is_active')),
            ])
            ->recordActions([EditAction::make()])
            ->emptyStateIcon('heroicon-o-arrow-path')
            ->emptyStateHeading(__('admin.recurring_expenses.plural'))
            ->emptyStateDescription(__('admin.recurring_expenses.empty_hint'));
    }
}
