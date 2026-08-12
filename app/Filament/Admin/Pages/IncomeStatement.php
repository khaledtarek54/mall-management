<?php

namespace App\Filament\Admin\Pages;

use App\Contracts\DeliverableReport;
use App\Filament\Admin\Concerns\PostsToLedger;
use App\Filament\Admin\Pages\Concerns\RendersFinancialStatement;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Filament\Admin\Pages\Concerns\ScopesLedgerReport;
use App\Services\Accounting\LedgerReportPdfService;
use App\Services\Accounting\LedgerReportService;
use App\Services\Reports\ReportCsvExporter;
use App\Support\ReportCsv;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * قائمة الدخل — Income Statement (P&L): revenue − expenses = net profit, for a
 * fiscal year, per property or consolidated.
 */
class IncomeStatement extends Page implements DeliverableReport, HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;
    use PostsToLedger;
    use RendersFinancialStatement;
    use SavesReportViews;
    use ScopesLedgerReport;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 24;

    protected string $view = 'filament.pages.ledger-report';

    protected static string $routePath = 'income-statement';

    public function getTitle(): string
    {
        return __('admin.reports.income_statement_title');
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->saveViewAction(),
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
                        $this->periodStart(),
                        $this->periodEnd(),
                        $this->propertyLabel(),
                        $this->periodLabel(),
                    );

                    return response()->streamDownload(
                        fn () => print ($pdf),
                        $svc->filename('income-statement', $this->periodSlug()),
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
            Action::make('export_csv')
                ->label(__('admin.reports.csv.export'))
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn () => $this->canViewReports())
                ->authorize(fn () => $this->canViewReports())
                ->action(function () {
                    $csv = $this->reportCsv();

                    return ReportCsv::stream($csv['filename'], $csv['headers'], $csv['rows']);
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

    /** @return array<string, mixed> */
    /**
     * The report as CSV, callable without a browser — see App\Contracts\DeliverableReport.
     *
     * The export action below and scheduled delivery both go through this, so an emailed copy is
     * byte-for-byte the report an operator would have downloaded.
     */
    public function reportCsv(): array
    {
        $csv = app(ReportCsvExporter::class)->incomeStatement($this->report());

        return [
            'filename' => "income-statement-{$this->periodSlug()}",
            'headers' => $csv['headers'],
            'rows' => $csv['rows'],
        ];
    }

    protected function report(): array
    {
        return app(LedgerReportService::class)->incomeStatement(
            $this->scopedAssetIds(),
            $this->periodStart(),
            $this->periodEnd(),
        );
    }

    public function table(Table $table): Table
    {
        return $this->statementTable($table)
            ->records(function (): array {
                $report = $this->report();

                return $this->statementRecords([
                    __('admin.reports.revenue') => [
                        'rows' => $report['revenue'],
                        'total' => $report['total_revenue'],
                        'total_label' => __('admin.reports.total_revenue'),
                    ],
                    __('admin.reports.expenses') => [
                        'rows' => $report['expense'],
                        'total' => $report['total_expense'],
                        'total_label' => __('admin.reports.total_expenses'),
                    ],
                    // Net profit stands as its own one-line section so it reads
                    // where an income statement puts it — under the two it derives
                    // from — instead of being tacked onto expenses.
                    __('admin.reports.net_profit') => [
                        'rows' => collect(),
                        'total' => $report['net_profit'],
                        'total_label' => __('admin.reports.net_profit'),
                    ],
                ]);
            });
    }
}
