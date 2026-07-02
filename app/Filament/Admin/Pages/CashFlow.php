<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Pages\Concerns\ScopesLedgerReport;
use App\Services\Accounting\LedgerReportPdfService;
use App\Services\Accounting\LedgerReportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * قائمة التدفقات النقدية — Cash-Flow Statement (indirect method): net income adjusted
 * for non-cash items and working-capital changes, plus investing + financing, for a
 * fiscal year, per property or consolidated. Reconciles to the actual cash movement.
 */
class CashFlow extends Page
{
    use ScopesLedgerReport;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 26;

    protected string $view = 'filament.pages.cash-flow';

    protected static string $routePath = 'cash-flow';

    public function getTitle(): string
    {
        return __('admin.reports.cash_flow_title');
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
                    $pdf = $svc->cashFlow(
                        $this->scopedAssetIds(),
                        Carbon::create($this->year, 1, 1)->startOfDay(),
                        Carbon::create($this->year, 12, 31)->endOfDay(),
                        $this->propertyLabel(),
                        $this->year,
                    );

                    return response()->streamDownload(
                        fn () => print($pdf),
                        $svc->filename('cash-flow', $this->year),
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.cash_flow');
    }

    protected function getViewData(): array
    {
        $from = Carbon::create($this->year, 1, 1)->startOfDay();
        $to = Carbon::create($this->year, 12, 31)->endOfDay();

        return array_merge($this->filterViewData(), [
            'report' => app(LedgerReportService::class)->cashFlow($this->scopedAssetIds(), $from, $to),
        ]);
    }
}
