<?php

namespace App\Filament\Admin\Resources\AccountingPeriods\Tables;

use App\Models\AccountingPeriod;
use App\Services\Accounting\PeriodService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountingPeriodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // No search box: AccountingPeriod carries no `search_text` blob (it is not a
            // record anyone hunts for by name) and this table marks no column
            // searchable. Without this, TableDefaults' blob search would still render
            // the box — and a search box that always returns nothing is worse than
            // none, because it reads as "no such row". See App\Support\SearchPolicy.
            ->searchable(false)
            ->modifyQueryUsing(fn ($query) => $query->with('fiscalYear'))
            ->columns([
                TextColumn::make('fiscalYear.year')
                    ->label(__('admin.fields.fiscal_year'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('period_no')
                    ->label(__('admin.fields.period'))
                    ->formatStateUsing(fn ($record) => $record->starts_on?->format('Y-m'))
                    ->fontFamily('mono')
                    ->sortable(),
                TextColumn::make('starts_on')
                    ->label(__('admin.fields.start_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('ends_on')
                    ->label(__('admin.fields.end_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.period.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'open' => 'success',
                        'closed' => 'gray',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.period')),
                SelectFilter::make('fiscal_year_id')
                    ->label(__('admin.fields.fiscal_year'))
                    // Newest year first. Filament falls back to ordering a relationship
                    // option list by its title attribute ASCENDING, which listed the years
                    // oldest-first (2024, 2025, 2026) — the reverse of the year picker in
                    // this page's own year-end-close modal and of every ledger report.
                    ->relationship('fiscalYear', 'year', fn (Builder $query) => $query->orderByDesc('year')),
            ])
            ->recordActions([
                // No read-only View here, deliberately — the five columns above ARE the record.
                // `accounting_periods` holds nothing else a person set, so the resource declares no
                // form, and a View action would render Filament's disabled-form modal from an empty
                // schema: a heading, a Close button and nothing between them. Closing and reopening
                // are the two things this screen exists for, and they are below.
                Action::make('close_period')
                    ->label(__('admin.actions.close_period'))
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->visible(fn (AccountingPeriod $record) => $record->status === 'open'
                        && auth()->user()?->can('accounting_periods.manage'))
                    ->authorize(fn () => auth()->user()?->can('accounting_periods.manage') ?? false)
                    ->requiresConfirmation()
                    ->action(function (AccountingPeriod $record): void {
                        try {
                            app(PeriodService::class)->closePeriod($record);
                        } catch (\DomainException $e) {
                            // Close gate: documents in this period aren't synced to the GL yet.
                            Notification::make()
                                ->title(__('admin.notifications.close_blocked_title'))
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();

                            return;
                        }
                        Notification::make()
                            ->title(__('admin.notifications.period_closed'))
                            ->success()
                            ->send();
                    }),
                Action::make('reopen_period')
                    ->label(__('admin.actions.reopen_period'))
                    ->icon('heroicon-o-lock-open')
                    ->color('warning')
                    ->visible(fn (AccountingPeriod $record) => $record->status === 'closed'
                        && auth()->user()?->can('accounting_periods.manage'))
                    ->authorize(fn () => auth()->user()?->can('accounting_periods.manage') ?? false)
                    ->requiresConfirmation()
                    ->action(function (AccountingPeriod $record): void {
                        try {
                            app(PeriodService::class)->reopenPeriod($record);
                        } catch (\DomainException $e) {
                            // The year's closing entry still stands, so anything posted into this
                            // month would never reach retained earnings. Persistent and shaped like
                            // the close gate above, because the escape it names is a different
                            // button in the header the operator has to go and find.
                            Notification::make()
                                ->title(__('admin.notifications.reopen_blocked_title'))
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('admin.notifications.period_reopened'))
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('starts_on', 'desc')
            ->paginated([12, 24, 48])
            ->emptyStateIcon('heroicon-o-calendar-days')
            ->emptyStateHeading(__('admin.empty.accounting_periods.heading'))
            ->emptyStateDescription(__('admin.empty.accounting_periods.description'));
    }
}
