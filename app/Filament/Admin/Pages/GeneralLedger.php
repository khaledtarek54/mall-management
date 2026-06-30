<?php

namespace App\Filament\Admin\Pages;

use App\Models\FiscalYear;
use App\Models\LedgerAccount;
use App\Services\Accounting\LedgerReportService;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * دفتر الأستاذ — General-ledger statement (كشف حساب) for one account: every
 * posted line in date order with a running balance, plus opening and closing.
 */
class GeneralLedger extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 23;

    protected string $view = 'filament.pages.general-ledger';

    protected static string $routePath = 'general-ledger';

    public int $year;

    public ?int $assetId = null;

    public ?int $accountId = null;

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
        return __('admin.reports.general_ledger_title');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.general_ledger');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.accounting');
    }

    protected function getViewData(): array
    {
        $from = Carbon::create($this->year, 1, 1)->startOfDay();
        $to = Carbon::create($this->year, 12, 31)->endOfDay();

        $account = $this->accountId ? LedgerAccount::find($this->accountId) : null;
        $statement = null;
        if ($account) {
            $statement = app(LedgerReportService::class)->accountLedger(
                $account,
                TenantScope::reportAssetIds($this->assetId ?: null),
                $from,
                $to,
            );
        }

        return [
            'account' => $account,
            'statement' => $statement,
            'years' => $this->yearOptions(),
            'properties' => ['' => __('admin.fields.property_consolidated')] + TenantScope::selectableAssetOptions(),
            'accounts' => $this->accountOptions(),
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

    /** @return array<int, string> Postable accounts incl. inactive (history is still viewable). */
    protected function accountOptions(): array
    {
        return LedgerAccount::postableOptions(activeOnly: false);
    }
}
