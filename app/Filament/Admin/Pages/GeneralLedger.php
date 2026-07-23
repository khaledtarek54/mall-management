<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\PostsToLedger;
use App\Filament\Admin\Pages\Concerns\ScopesLedgerReport;
use App\Models\LedgerAccount;
use App\Services\Accounting\LedgerReportService;
use App\Services\Reports\ReportCsvExporter;
use App\Support\ReportCsv;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * دفتر الأستاذ — General-ledger statement (كشف حساب) for one account: every
 * posted line in date order with a running balance, plus opening and closing.
 */
class GeneralLedger extends Page
{
    use PostsToLedger;
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

    protected function getHeaderActions(): array
    {
        return [
            $this->postToLedgerAction(),
            // The GL had NO export at all — yet it is the raw transaction detail an accountant
            // reconciles against, the report they most want in a spreadsheet. Enabled once an
            // account is selected (there is nothing to export otherwise).
            Action::make('export_csv')
                ->label(__('admin.reports.csv.export'))
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn () => $this->canViewReports() && $this->accountId !== null)
                ->authorize(fn () => $this->canViewReports())
                ->action(function () {
                    $account = LedgerAccount::find($this->accountId);
                    abort_unless($account !== null, 404);

                    $statement = app(LedgerReportService::class)->accountLedger(
                        $account,
                        $this->scopedAssetIds(),
                        Carbon::create($this->year, 1, 1)->startOfDay(),
                        Carbon::create($this->year, 12, 31)->endOfDay(),
                    );
                    $csv = app(ReportCsvExporter::class)->generalLedger($statement);

                    return ReportCsv::stream("general-ledger-{$account->code}-{$this->year}", $csv['headers'], $csv['rows']);
                }),
        ];
    }

    public function getSubheading(): ?string
    {
        return $this->ledgerLastSyncedSubheading();
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
