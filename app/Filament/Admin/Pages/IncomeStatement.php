<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\PostsToLedger;
use App\Filament\Admin\Pages\Concerns\ScopesLedgerReport;
use App\Services\Accounting\LedgerReportPdfService;
use App\Services\Accounting\LedgerReportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * قائمة الدخل — Income Statement (P&L): revenue − expenses = net profit, for a
 * fiscal year, per property or consolidated.
 */
class IncomeStatement extends Page
{
    use PostsToLedger;
    use ScopesLedgerReport;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 24;

    protected string $view = 'filament.pages.income-statement';

    protected static string $routePath = 'income-statement';

    public function getTitle(): string
    {
        return __('admin.reports.income_statement_title');
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->postToLedgerAction(),
            Action::make('download_pdf')
                ->label(__('admin.actions.download_pdf'))
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn () => $this->canViewReports())
                ->authorize(fn () => $this->canViewReports())
                ->action(function () {
                    $svc = app(LedgerReportPdfService::class);
                    $pdf = $svc->incomeStatement(
                        $this->scopedAssetIds(),
                        Carbon::create($this->year, 1, 1)->startOfDay(),
                        Carbon::create($this->year, 12, 31)->endOfDay(),
                        $this->propertyLabel(),
                        $this->year,
                    );

                    return response()->streamDownload(
                        fn () => print($pdf),
                        $svc->filename('income-statement', $this->year),
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
        ];
    }

    public function getSubheading(): ?string
    {
        return $this->ledgerLastSyncedSubheading();
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.income_statement');
    }

    protected function getViewData(): array
    {
        $from = Carbon::create($this->year, 1, 1)->startOfDay();
        $to = Carbon::create($this->year, 12, 31)->endOfDay();

        return array_merge($this->filterViewData(), [
            'report' => app(LedgerReportService::class)->incomeStatement($this->scopedAssetIds(), $from, $to),
        ]);
    }
}
