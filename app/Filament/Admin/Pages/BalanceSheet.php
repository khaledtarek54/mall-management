<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Pages\Concerns\ScopesLedgerReport;
use App\Services\Accounting\LedgerReportService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * قائمة المركز المالي — Balance Sheet as of a year-end: Assets vs Liabilities +
 * Equity + net income, per property or consolidated.
 */
class BalanceSheet extends Page
{
    use ScopesLedgerReport;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 25;

    protected string $view = 'filament.pages.balance-sheet';

    protected static string $routePath = 'balance-sheet';

    public function getTitle(): string
    {
        return __('admin.reports.balance_sheet_title');
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
