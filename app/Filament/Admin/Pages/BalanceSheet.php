<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\PostsToLedger;
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
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * قائمة المركز المالي — Balance Sheet as of a year-end: Assets vs Liabilities +
 * Equity + net income, per property or consolidated.
 */
class BalanceSheet extends Page implements HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;
    use PostsToLedger;
    use RendersFinancialStatement;
    use ScopesLedgerReport;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 25;

    protected string $view = 'filament.pages.ledger-report';

    protected static string $routePath = 'balance-sheet';

    public function getTitle(): string
    {
        return __('admin.reports.balance_sheet_title');
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
                    $pdf = $svc->balanceSheet(
                        $this->scopedAssetIds(),
                        $this->periodEnd(),
                        $this->propertyLabel(),
                    );

                    return response()->streamDownload(
                        fn () => print ($pdf),
                        $svc->filename('balance-sheet', $this->periodSlug()),
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
                    $csv = app(ReportCsvExporter::class)->balanceSheet($this->report());

                    return ReportCsv::stream("balance-sheet-{$this->periodSlug()}", $csv['headers'], $csv['rows']);
                }),
        ];
    }

    /**
     * Whether the sheet balances leads the subheading — Assets ≡ Liabilities +
     * Equity + net income is the assertion the whole statement rests on.
     */
    public function getSubheading(): ?string
    {
        $check = $this->report()['balanced']
            ? '✓ '.__('admin.reports.balanced')
            : '✗ '.__('admin.reports.not_balanced');

        $sync = $this->ledgerLastSyncedSubheading();

        return $sync ? $check.' · '.$sync : $check;
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.balance_sheet');
    }

    /** @return array<string, mixed> */
    protected function report(): array
    {
        return app(LedgerReportService::class)->balanceSheet($this->scopedAssetIds(), $this->periodEnd());
    }

    public function table(Table $table): Table
    {
        return $this->statementTable($table)
            ->records(function (): array {
                $report = $this->report();

                return $this->statementRecords([
                    __('admin.reports.assets') => [
                        'rows' => $report['assets'],
                        'total' => $report['total_assets'],
                        'total_label' => __('admin.reports.total_assets'),
                    ],
                    __('admin.reports.liabilities_equity') => [
                        // Net income for the period is presented as its own line:
                        // it is real equity that has not yet been closed to
                        // retained earnings, and without it the section would not
                        // foot against assets.
                        'rows' => $report['liabilities']
                            ->concat($report['equity'])
                            ->push([
                                'code' => null,
                                'name_en' => __('admin.reports.net_income_period'),
                                'name_ar' => __('admin.reports.net_income_period'),
                                'amount' => $report['net_income'],
                            ]),
                        'total' => $report['total_equity_and_liabilities'],
                        'total_label' => __('admin.reports.total_equity_and_liabilities'),
                    ],
                ]);
            });
    }
}
