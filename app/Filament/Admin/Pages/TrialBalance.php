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

/**
 * ميزان المراجعة — Trial Balance. Every account with movement, its total debit
 * and credit, and the net on its normal side. The two column totals must match.
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
        $check = $this->report()['balanced']
            ? '✓ '.__('admin.reports.balanced')
            : '✗ '.__('admin.reports.not_balanced');

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

        return [
            'filename' => "trial-balance-{$this->periodSlug()}",
            'headers' => $csv['headers'],
            'rows' => $csv['rows'],
        ];
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

    protected function report(): array
    {
        return app(LedgerReportService::class)->trialBalance(
            $this->scopedAssetIds(),
            $this->periodStart(),
            $this->periodEnd(),
            $this->includeZeroBalances,
        );
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
                    ->description(fn (array $record): string => __("admin.enums.ledger_account_type.{$record['type']}")),
                TextColumn::make('debit_balance')
                    ->label(__('admin.fields.debit'))
                    ->money('EGP')
                    ->alignEnd()
                    // A zero on one side is noise — the eye runs down whichever
                    // column the account actually sits in.
                    ->state(fn (array $record) => $record['debit_balance'] > 0 ? $record['debit_balance'] : null)
                    ->placeholder('—')
                    ->summarize(
                        Summarizer::make('total')
                            ->label(__('admin.reports.totals'))
                            ->money('EGP')
                            // Off the report, not the paginated page: this total is
                            // half of the tie-out the whole statement is judged on.
                            ->using(fn (): float => $this->report()['total_debit'])
                    ),
                TextColumn::make('credit_balance')
                    ->label(__('admin.fields.credit'))
                    ->money('EGP')
                    ->alignEnd()
                    ->state(fn (array $record) => $record['credit_balance'] > 0 ? $record['credit_balance'] : null)
                    ->placeholder('—')
                    ->summarize(
                        Summarizer::make('total')
                            ->label(__('admin.reports.totals'))
                            ->money('EGP')
                            ->using(fn (): float => $this->report()['total_credit'])
                    ),
            ])
            // A trial balance is read as one continuous statement that has to
            // foot; paginating it would split the totals off their rows.
            ->paginated(false)
            ->emptyStateIcon('heroicon-o-scale')
            ->emptyStateHeading(__('admin.reports.no_movements'))
            ->emptyStateDescription(__('admin.reports.no_movements_hint'));
    }
}
