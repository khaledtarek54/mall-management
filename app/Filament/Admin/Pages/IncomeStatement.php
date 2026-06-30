<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Pages\Concerns\ScopesLedgerReport;
use App\Services\Accounting\LedgerReportService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * قائمة الدخل — Income Statement (P&L): revenue − expenses = net profit, for a
 * fiscal year, per property or consolidated.
 */
class IncomeStatement extends Page
{
    use ScopesLedgerReport;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 24;

    protected string $view = 'filament.pages.income-statement';

    protected static string $routePath = 'income-statement';

    public function getTitle(): string
    {
        return __('admin.reports.income_statement_title');
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
