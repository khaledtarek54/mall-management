<?php

namespace App\Filament\Admin\Resources\ExpenseCategories\Tables;

use App\Models\ExpenseCategory;
use App\Support\CostNature;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ExpenseCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('code')->label(__('admin.fields.code'))->searchable()->sortable(),

                TextColumn::make('label')
                    ->label(__('admin.fields.name'))
                    ->state(fn (ExpenseCategory $record): string => $record->label()),

                TextColumn::make('ledgerAccount.name')
                    ->label(__('admin.fields.ledger_account'))
                    // The FLOOR is what matters to read: null is the normal state, and a blank cell
                    // would say "posts nowhere" when it actually posts to the mapped role.
                    ->state(fn (ExpenseCategory $record): string => $record->ledgerAccount?->name
                        ?? __('admin.expense_categories.floor'))
                    ->badge()
                    ->color(fn (ExpenseCategory $record): string => $record->ledger_account_id !== null ? 'success' : 'gray'),

                TextColumn::make('cost_nature')
                    ->label(__('admin.fields.cost_nature'))
                    ->formatStateUsing(fn (?string $state) => $state ? __("admin.enums.cost_nature.{$state}") : '—')
                    ->badge(),

                IconColumn::make('is_active')->label(__('admin.fields.is_active'))->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('admin.fields.is_active')),
                SelectFilter::make('cost_nature')
                    ->label(__('admin.fields.cost_nature'))
                    ->options(fn () => collect(CostNature::NATURES)
                        ->mapWithKeys(fn (string $n) => [$n => __("admin.enums.cost_nature.{$n}")])->all()),
            ])
            ->recordActions([
                // A read-only view, for the role that holds `.view` and not `.edit`. Its schema is the
                // resource's own form rendered disabled, so it cannot drift from the fields that exist.
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
