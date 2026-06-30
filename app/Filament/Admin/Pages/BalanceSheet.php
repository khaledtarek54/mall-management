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
 * قائمة المركز المالي — Balance Sheet as of a year-end: Assets vs Liabilities +
 * Equity + net income, per property or consolidated.
 */
class BalanceSheet extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 25;

    protected string $view = 'filament.pages.balance-sheet';

    protected static string $routePath = 'balance-sheet';

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
        return __('admin.reports.balance_sheet_title');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.balance_sheet');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.accounting');
    }

    protected function getViewData(): array
    {
        $asOf = Carbon::create($this->year, 12, 31)->endOfDay();

        return [
            'report' => app(LedgerReportService::class)->balanceSheet(
                TenantScope::reportAssetIds($this->assetId ?: null),
                $asOf,
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
