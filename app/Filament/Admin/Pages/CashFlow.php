<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Pages\Concerns\RendersFinancialStatement;
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
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * قائمة التدفقات النقدية — Cash-Flow Statement (indirect method): net income adjusted
 * for non-cash items and working-capital changes, plus investing + financing, for a
 * fiscal year, per property or consolidated. Reconciles to the actual cash movement.
 */
class CashFlow extends Page implements HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;
    use RendersFinancialStatement;
    use ScopesLedgerReport;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 26;

    protected string $view = 'filament.pages.ledger-report';

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
                        $this->periodStart(),
                        $this->periodEnd(),
                        $this->propertyLabel(),
                        $this->year,
                    );

                    return response()->streamDownload(
                        fn () => print ($pdf),
                        $svc->filename('cash-flow', $this->year),
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
                    $report = app(LedgerReportService::class)->cashFlow(
                        $this->scopedAssetIds(),
                        $this->periodStart(),
                        $this->periodEnd(),
                    );
                    $csv = app(ReportCsvExporter::class)->cashFlow($report);

                    return ReportCsv::stream("cash-flow-{$this->year}", $csv['headers'], $csv['rows']);
                }),
        ];
    }

    /**
     * The integrity check leads the subheading: a cash-flow statement that does
     * not tie to the actual movement in the cash accounts is wrong, and that
     * has to be impossible to scroll past.
     */
    public function getSubheading(): ?string
    {
        return $this->report()['reconciled']
            ? __('admin.reports.cash_flow_reconciled')
            : __('admin.reports.cash_flow_unreconciled');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.cash_flow');
    }

    /** @return array<string, mixed> */
    protected function report(): array
    {
        return app(LedgerReportService::class)->cashFlow(
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
                    // Indirect method: the operating section opens with net income
                    // and then lists the non-cash add-backs and working-capital
                    // moves that reconcile it to cash.
                    __('admin.reports.operating_activities') => [
                        'rows' => collect([[
                            'code' => null,
                            'name_en' => __('admin.reports.net_income'),
                            'name_ar' => __('admin.reports.net_income'),
                            'amount' => $report['net_income'],
                        ]])->concat($report['adjustments']),
                        'total' => $report['operating_total'],
                        'total_label' => __('admin.reports.net_cash_operating'),
                    ],
                    __('admin.reports.investing_activities') => [
                        'rows' => $report['investing'],
                        'total' => $report['investing_total'],
                        'total_label' => __('admin.reports.net_cash_investing'),
                    ],
                    __('admin.reports.financing_activities') => [
                        'rows' => $report['financing'],
                        'total' => $report['financing_total'],
                        'total_label' => __('admin.reports.net_cash_financing'),
                    ],
                    // Opening + net change = closing. This is the section that
                    // proves the three above actually explain the cash movement.
                    __('admin.reports.csv.net_change') => [
                        'rows' => collect([
                            [
                                'code' => null,
                                'name_en' => __('admin.reports.cash_at_start'),
                                'name_ar' => __('admin.reports.cash_at_start'),
                                'amount' => $report['cash_opening'],
                            ],
                            [
                                'code' => null,
                                'name_en' => __('admin.reports.csv.net_change'),
                                'name_ar' => __('admin.reports.csv.net_change'),
                                'amount' => $report['net_change'],
                            ],
                        ]),
                        'total' => $report['cash_closing'],
                        'total_label' => __('admin.reports.cash_at_end'),
                    ],
                ]);
            });
    }
}
