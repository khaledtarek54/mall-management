<?php

namespace App\Filament\Admin\Resources\MarketingBudgets\Tables;

use App\Filament\Admin\Resources\MarketingBudgets\MarketingBudgetResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

class MarketingBudgetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('asset.name')
                    ->label(__('admin.tables.marketing_budget.property'))
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('period_year')
                    ->label(__('admin.tables.marketing_budget.year'))
                    ->sortable(),
                TextColumn::make('accrued_amount')
                    ->label(__('admin.tables.marketing_budget.accrued'))
                    ->money('EGP')
                    ->color('success')
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('spent_amount')
                    ->label(__('admin.tables.marketing_budget.spent'))
                    ->money('EGP')
                    ->color('danger')
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('balance')
                    ->label(__('admin.tables.marketing_budget.balance'))
                    ->money('EGP')
                    ->state(fn ($record) => $record->balance())
                    ->weight('bold')
                    // Over budget (spent past the collected levy) must stand out — the owner's whole
                    // reason for this screen is to see whether the operator stayed within the fund.
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : 'success'),
                TextColumn::make('status')
                    ->label(__('admin.tables.marketing_budget.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.marketing_budget_status.{$state}"))
                    ->color(fn (string $state) => $state === 'open' ? 'success' : 'gray'),
            ])
            ->defaultSort('period_year', 'desc')
            ->filters([
                // Surface the budgets that need attention — spent past the collected levy.
                Filter::make('over_budget')
                    ->label(__('admin.tables.marketing_budget.over_budget'))
                    ->query(fn ($query) => $query->whereColumn('spent_amount', '>', 'accrued_amount')),
            ])
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => MarketingBudgetResource::canView($record))
                    ->authorize(fn ($record) => MarketingBudgetResource::canView($record)),
                EditAction::make()->visible(fn ($record) => MarketingBudgetResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->visible(fn () => MarketingBudgetResource::canDeleteAny()),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-megaphone')
            ->emptyStateHeading(__('admin.empty.marketing_budgets.heading'))
            ->emptyStateDescription(__('admin.empty.marketing_budgets.description'));
    }
}
