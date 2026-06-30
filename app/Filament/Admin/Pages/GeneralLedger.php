<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Pages\Concerns\ScopesLedgerReport;
use App\Models\LedgerAccount;
use App\Services\Accounting\LedgerReportService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * دفتر الأستاذ — General-ledger statement (كشف حساب) for one account: every
 * posted line in date order with a running balance, plus opening and closing.
 */
class GeneralLedger extends Page
{
    use ScopesLedgerReport;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 23;

    protected string $view = 'filament.pages.general-ledger';

    protected static string $routePath = 'general-ledger';

    public ?int $accountId = null;

    public function getTitle(): string
    {
        return __('admin.reports.general_ledger_title');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.general_ledger');
    }

    protected function getViewData(): array
    {
        $from = Carbon::create($this->year, 1, 1)->startOfDay();
        $to = Carbon::create($this->year, 12, 31)->endOfDay();

        $account = $this->accountId ? LedgerAccount::find($this->accountId) : null;
        $statement = $account
            ? app(LedgerReportService::class)->accountLedger($account, $this->scopedAssetIds(), $from, $to)
            : null;

        return array_merge($this->filterViewData(), [
            'account' => $account,
            'statement' => $statement,
            'accounts' => LedgerAccount::postableOptions(activeOnly: false),
        ]);
    }
}
