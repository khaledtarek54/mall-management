<?php

namespace App\Filament\Admin\Resources\AccountingPeriods\Pages;

use App\Filament\Admin\Resources\AccountingPeriods\AccountingPeriodResource;
use App\Models\FiscalYear;
use App\Services\Accounting\PeriodService;
use App\Services\Accounting\YearEndCloseService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListAccountingPeriods extends ListRecords
{
    protected static string $resource = AccountingPeriodResource::class;

    /** @return callable */
    private function canManage(): callable
    {
        return fn () => auth()->user()?->can('accounting_periods.manage') ?? false;
    }

    private function yearSelect(): Select
    {
        return Select::make('year')
            ->label(__('admin.fields.fiscal_year'))
            ->options(fn () => FiscalYear::query()->orderByDesc('year')->pluck('year', 'year'))
            ->native(false)
            ->required();
    }

    protected function getHeaderActions(): array
    {
        return [
            // Close the year: post the closing entry (while periods are open), THEN
            // lock the year's periods — this order avoids the "can't post into a
            // closed December" trap. authorize() enforces the permission server-side
            // (visible() only hides the button).
            Action::make('year_end_close')
                ->label(__('admin.actions.year_end_close'))
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible($this->canManage())
                ->authorize($this->canManage())
                ->schema([$this->yearSelect()])
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.year_end_close_confirm'))
                ->action(function (array $data): void {
                    $year = (int) $data['year'];

                    $entry = app(YearEndCloseService::class)->close($year);
                    if ($fiscalYear = FiscalYear::where('year', $year)->first()) {
                        app(PeriodService::class)->closeFiscalYear($fiscalYear);
                    }

                    Notification::make()
                        ->title(__('admin.notifications.year_end_closed'))
                        ->body($entry?->number ?? __('admin.notifications.year_end_nothing'))
                        ->success()
                        ->send();
                }),

            // Reopen the year: UNLOCK its periods first, THEN void the closing entry
            // so the reversal posts back inside the same (now-open) year.
            Action::make('year_end_reopen')
                ->label(__('admin.actions.year_end_reopen'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible($this->canManage())
                ->authorize($this->canManage())
                ->schema([$this->yearSelect()])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    $year = (int) $data['year'];

                    if ($fiscalYear = FiscalYear::where('year', $year)->first()) {
                        app(PeriodService::class)->reopenFiscalYear($fiscalYear);
                    }
                    app(YearEndCloseService::class)->reopen($year);

                    Notification::make()
                        ->title(__('admin.notifications.year_end_reopened'))
                        ->success()
                        ->send();
                }),
        ];
    }
}
