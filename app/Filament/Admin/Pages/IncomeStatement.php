<?php

namespace App\Filament\Admin\Pages;

use App\Models\FiscalYear;
use App\Services\Accounting\LedgerReportService;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * قائمة الدخل — Income Statement (P&L): revenue − expenses = net profit, for a
 * fiscal year, per property or consolidated.
 */
class IncomeStatement extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 24;

    protected string $view = 'filament.pages.income-statement';

    protected static string $routePath = 'income-statement';

    public int $year;

    public ?int $assetId = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->can('general_ledger.view') ?? false;
    }

    public function mount(): void
    {
        $this->year = (int) now()->year;
    }

    public function getTitle(): string
    {
        return __('admin.reports.income_statement_title');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.income_statement');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.accounting');
    }

    protected function getViewData(): array
    {
        $from = Carbon::create($this->year, 1, 1)->startOfDay();
        $to = Carbon::create($this->year, 12, 31)->endOfDay();

        return [
            'report' => app(LedgerReportService::class)->incomeStatement(
                TenantScope::reportAssetIds($this->assetId ?: null),
                $from,
                $to,
            ),
            'years' => $this->yearOptions(),
            'properties' => ['' => __('admin.fields.property_consolidated')] + TenantScope::selectableAssetOptions(),
            'locale' => app()->getLocale(),
        ];
    }

    /** @return array<int, int> */
    protected function yearOptions(): array
    {
        $years = FiscalYear::query()->orderByDesc('year')->pluck('year')->all();
        if (empty($years)) {
            $years = [(int) now()->year];
        }

        return array_combine($years, $years);
    }
}
