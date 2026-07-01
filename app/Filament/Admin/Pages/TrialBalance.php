<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Pages\Concerns\ScopesLedgerReport;
use App\Services\Accounting\LedgerReportPdfService;
use App\Services\Accounting\LedgerReportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * ميزان المراجعة — Trial Balance. Every account with movement, its total debit
 * and credit, and the net on its normal side. The two column totals must match.
 */
class TrialBalance extends Page
{
    use ScopesLedgerReport;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 22;

    protected string $view = 'filament.pages.trial-balance';

    protected static string $routePath = 'trial-balance';

    public function getTitle(): string
    {
        return __('admin.reports.trial_balance_title');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download_pdf')
                ->label(__('admin.actions.download_pdf'))
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn () => $this->canViewReports())
                ->authorize(fn () => $this->canViewReports())
                ->action(function () {
                    $svc = app(LedgerReportPdfService::class);
                    $pdf = $svc->trialBalance(
                        $this->scopedAssetIds(),
                        Carbon::create($this->year, 1, 1)->startOfDay(),
                        Carbon::create($this->year, 12, 31)->endOfDay(),
                        $this->propertyLabel(),
                        $this->year,
                    );

                    return response()->streamDownload(
                        fn () => print($pdf),
                        $svc->filename('trial-balance', $this->year),
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.trial_balance');
    }

    protected function getViewData(): array
    {
        $from = Carbon::create($this->year, 1, 1)->startOfDay();
        $to = Carbon::create($this->year, 12, 31)->endOfDay();

        return array_merge($this->filterViewData(), [
            'report' => app(LedgerReportService::class)->trialBalance($this->scopedAssetIds(), $from, $to),
        ]);
    }
}
