<?php

namespace App\Filament\Admin\Pages;

use App\Contracts\DeliverableReport;
use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Concerns\PostsToLedger;
use App\Filament\Admin\Pages\Concerns\ExportsReport;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Filament\Admin\Pages\Concerns\ScopesLedgerReport;
use App\Services\Accounting\LedgerReportPdfService;
use App\Services\Accounting\LedgerReportService;
use App\Services\Reports\ReportCsvExporter;
use App\Support\ReportPreferences;
use App\Support\StatementIntegrity;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * ميزان المراجعة — Trial Balance. Every account with a balance or a movement in the
 * window: the balance brought forward, the window's debit and credit, and the closing
 * balance, each on its own side. All three column pairs must foot.
 *
 * Rendered as a native Filament table over the report service's computed rows
 * (`records()`, not `query()` — a trial balance is an aggregate per account, not
 * a row set). That buys sorting, column control and a real footer tie-out, and
 * replaces the hand-written <table> with inline styles this page used to ship.
 */
class TrialBalance extends Page implements DeliverableReport, HasSchemas, HasTable
{
    use ExportsReport;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use PostsToLedger;
    use SavesReportViews;
    use ScopesLedgerReport;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected string $view = 'filament.pages.ledger-report';

    protected static string $routePath = 'trial-balance';

    public function getTitle(): string
    {
        return __('admin.reports.trial_balance_title');
    }

    /**
     * The balance check, as the page subheading rather than a bespoke coloured
     * div: whether the ledger foots is the single fact this page exists to
     * report, so it belongs next to the title.
     */
    public function getSubheading(): ?string
    {
        $check = StatementIntegrity::balance((bool) $this->report()['balanced']);

        $sync = $this->ledgerLastSyncedSubheading();

        return $sync ? $check.' · '.$sync : $check;
    }

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
            $this->saveViewAction(),
            $this->postToLedgerAction(),
            Action::make('download_pdf')
                ->label(__('admin.actions.download_pdf'))
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn () => $this->canViewReports())
                ->authorize(fn () => $this->canViewReports())
                ->action(function () {
                    $svc = app(LedgerReportPdfService::class);
                    $pdf = $svc->trialBalance(
                        $this->scopedAssetIds(),
                        $this->periodStart(),
                        $this->periodEnd(),
                        $this->propertyLabel(),
                        $this->periodLabel(),
                        // "Show accounts with no movement" travels to the PRINTED copy. It reaches
                        // the screen and the CSV through `report()`; the PDF is built by a different
                        // service and took the default, so ticking the toggle and pressing Download
                        // handed the operator a statement without them. Named, because the
                        // parameter sits after $locale.
                        includeZeroBalances: $this->includeZeroBalances,
                    );

                    return response()->streamDownload(
                        fn () => print ($pdf),
                        $svc->filename('trial-balance', $this->periodSlug()),
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
            // CSV, not just PDF — the accountant works the trial balance in a spreadsheet
            // (reconcile, pivot, hand to an auditor). A PDF can only be looked at.
            ...$this->exportActions(),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.trial_balance');
    }

    /** @return array<string, mixed> */
    /**
     * The report as CSV, callable without a browser — see App\Contracts\DeliverableReport.
     *
     * The export action below and scheduled delivery both go through this, so an emailed copy is
     * byte-for-byte the report an operator would have downloaded.
     */
    public function reportCsv(): array
    {
        $csv = app(ReportCsvExporter::class)->trialBalance($this->report());

        return $this->withUnallocatedNotice([
            'filename' => "trial-balance-{$this->periodSlug()}",
            'headers' => $csv['headers'],
            'rows' => $csv['rows'],
        ]);
    }

    /**
     * List postable accounts that had no movement at all (RP-02).
     *
     * Off by default: a trial balance of 400 rows, 300 of them zero, is harder to read rather than
     * more complete. On, it answers the question this report exists for — "is that account really
     * nil, or did nobody map it?" — which absence cannot answer either way.
     *
     * A public typed scalar, so it travels like every other report parameter: into the URL, a saved
     * view and a scheduled delivery. It is remembered per user too, because unlike a date it says
     * how this person reads a trial balance rather than which moment they wanted.
     */
    public bool $includeZeroBalances = false;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(['sm' => 2, 'lg' => 4])
                ->schema([
                    ...$this->ledgerFilterComponents(),
                    Toggle::make('includeZeroBalances')
                        ->label(__('admin.reports.include_zero_balances'))
                        ->helperText(__('admin.reports.include_zero_balances_help'))
                        ->live()
                        ->afterStateUpdated(fn ($livewire) => ReportPreferences::remember($livewire)),
                ]),
        ]);
    }

    /**
     * The report, ONCE per request. It is read by the subheading, the rows and all six column
     * totals — measured before the memo, eight calls and sixteen GROUP-BY aggregates over
     * `journal_lines` for one render. Keyed on everything that changes the answer, because a
     * Livewire property moves between two calls in one request when a filter is being applied.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $reportMemo = [];

    protected function report(): array
    {
        $key = json_encode([$this->scopedAssetIds(), $this->periodStart()->toDateString(), $this->periodEnd()->toDateString(), $this->includeZeroBalances]);

        return $this->reportMemo[$key] ??= app(LedgerReportService::class)->trialBalance(
            $this->scopedAssetIds(),
            $this->periodStart(),
            $this->periodEnd(),
            $this->includeZeroBalances,
        );
    }

    /**
     * The closing balance is an *as at* figure now, so what the statement is missing is every
     * unallocated entry up to the date — the same reason `BalanceSheet::unallocatedRange()` is
     * open-ended. Bounded to the month, the notice under-counted (a null-property entry dated
     * before the window sits in nobody's opening and was reported nowhere) and on a month with no
     * such entry it fell silent while the opening column was still short.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    protected function unallocatedRange(): array
    {
        return [null, $this->periodEnd()];
    }

    public function table(Table $table): Table
    {
        $locale = app()->getLocale();

        return $table
            ->records(fn (): array => $this->report()['rows']
                ->map(fn (array $row): array => [
                    'id' => $row['account_id'],
                    'code' => $row['code'],
                    'account' => $locale === 'ar' ? $row['name_ar'] : $row['name_en'],
                    'type' => $row['type'],
                    'opening_debit' => $row['opening_debit'],
                    'opening_credit' => $row['opening_credit'],
                    'debit_total' => $row['debit_total'],
                    'credit_total' => $row['credit_total'],
                    'debit_balance' => $row['debit_balance'],
                    'credit_balance' => $row['credit_balance'],
                ])
                ->all())
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.tables.ledger_account.code'))
                    ->fontFamily('mono')
                    ->size('sm'),
                TextColumn::make('account')
                    ->label(__('admin.tables.ledger_account.account'))
                    ->weight('medium')
                    ->description(fn (array $record): string => __("admin.enums.ledger_account_type.{$record['type']}"))
                    // Into the general ledger for THIS account, over the period and property the
                    // trial balance was run for — the same link the income statement and the
                    // balance sheet have carried since the drill-down shipped, through the same
                    // builder. The row already held the account id (`'id' => $row['account_id']`,
                    // above) and nothing opened it, so the one screen an accountant uses to ask
                    // "what is IN 11101?" was the one screen that could not answer.
                    ->url(fn (array $record): ?string => $this->ledgerUrlForAccount($record['id'] ?? null))
                    ->color(fn (array $record): ?string => $this->ledgerUrlForAccount($record['id'] ?? null) ? 'primary' : null),
                // Three column pairs, each footing on its own — opening, the window's movement,
                // closing (2026-09-11). Until then the screen printed the window's NET MOVEMENT
                // under "Debit / Credit", which for any window narrower than the whole ledger is
                // not a balance: the August bank line read Dr 17,000 where the account stood at
                // Cr 1,948,000. The rows are the service's own; nothing here re-derives.
                ...$this->pairColumns('opening_debit', 'opening_credit', 'opening', 'total_opening_debit', 'total_opening_credit'),
                ...$this->pairColumns('debit_total', 'credit_total', 'movement', 'total_movement_debit', 'total_movement_credit'),
                ...$this->pairColumns('debit_balance', 'credit_balance', 'closing', 'total_debit', 'total_credit'),
            ])
            // A trial balance is read as one continuous statement that has to
            // foot; paginating it would split the totals off their rows.
            ->paginated(false)
            ->emptyStateIcon('heroicon-o-scale')
            ->emptyStateHeading(__('admin.reports.no_movements'))
            ->emptyStateDescription(__('admin.reports.no_movements_hint'));
    }

    /**
     * One debit/credit pair of the trial balance — the label is the pair's own name, the total is
     * read off the REPORT rather than the paginated page, because each pair is half of a tie-out
     * the whole statement is judged on.
     *
     * @return array<int, TextColumn>
     */
    private function pairColumns(string $debitKey, string $creditKey, string $pair, string $debitTotal, string $creditTotal): array
    {
        $column = fn (string $key, string $label, string $total): TextColumn => TextColumn::make($key)
            ->label(__("admin.reports.trial_balance_columns.{$label}"))
            ->money('EGP')
            ->alignEnd()
            // A zero on one side is noise — the eye runs down whichever column the account
            // actually sits in.
            ->state(fn (array $record) => ($record[$key] ?? 0) > 0 ? $record[$key] : null)
            ->placeholder('—')
            ->summarize(
                Summarizer::make('total')
                    ->label(__('admin.reports.totals'))
                    ->money('EGP')
                    // No `?? 0`: a mistyped total key must throw, not print 0.00 under "Totals".
                    ->using(fn (): float => (float) $this->report()[$total])
            );

        return [
            $column($debitKey, "{$pair}_debit", $debitTotal),
            $column($creditKey, "{$pair}_credit", $creditTotal),
        ];
    }
}
