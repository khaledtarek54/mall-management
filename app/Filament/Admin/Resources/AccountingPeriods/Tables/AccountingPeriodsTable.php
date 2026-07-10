<?php

namespace App\Filament\Admin\Resources\AccountingPeriods\Tables;

use App\Models\AccountingPeriod;
use App\Services\Accounting\PeriodService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AccountingPeriodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
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
                    ->relationship('fiscalYear', 'year'),
            ])
            ->recordActions([
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
                        app(PeriodService::class)->reopenPeriod($record);
                        Notification::make()
                            ->title(__('admin.notifications.period_reopened'))
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('starts_on', 'desc')
            ->paginated([12, 24, 48]);
    }
}
