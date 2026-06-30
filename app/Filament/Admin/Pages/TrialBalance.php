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
 * ميزان المراجعة — Trial Balance. Every account with movement, its total debit
 * and credit, and the net on its normal side. The two column totals must match.
 */
class TrialBalance extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 22;

    protected string $view = 'filament.pages.trial-balance';

    protected static string $routePath = 'trial-balance';

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
        return __('admin.reports.trial_balance_title');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.trial_balance');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.accounting');
    }

    protected function getViewData(): array
    {
        $from = Carbon::create($this->year, 1, 1)->startOfDay();
        $to = Carbon::create($this->year, 12, 31)->endOfDay();

        $report = app(LedgerReportService::class)->trialBalance(
            TenantScope::reportAssetIds($this->assetId ?: null),
            $from,
            $to,
        );

        return [
            'report' => $report,
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
