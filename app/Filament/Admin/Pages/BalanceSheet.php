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
 * قائمة المركز المالي — Balance Sheet as of a year-end: Assets vs Liabilities +
 * Equity + net income, per property or consolidated.
 */
class BalanceSheet extends Page
{
    use PostsToLedger;
    use ScopesLedgerReport;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 25;

    protected string $view = 'filament.pages.balance-sheet';

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
                        Carbon::create($this->year, 12, 31)->endOfDay(),
                        $this->propertyLabel(),
                    );

                    return response()->streamDownload(
                        fn () => print($pdf),
                        $svc->filename('balance-sheet', $this->year),
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
        return __('admin.navigation.balance_sheet');
    }

    protected function getViewData(): array
    {
        $asOf = Carbon::create($this->year, 12, 31)->endOfDay();

        return array_merge($this->filterViewData(), [
            'report' => app(LedgerReportService::class)->balanceSheet($this->scopedAssetIds(), $asOf),
        ]);
    }
}
