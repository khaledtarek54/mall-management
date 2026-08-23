<?php

namespace App\Filament\Admin\Pages;

use App\Contracts\DeliverableReport;
use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Concerns\PostsToLedger;
use App\Filament\Admin\Pages\Concerns\ExportsReport;
use App\Filament\Admin\Pages\Concerns\RendersFinancialStatement;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Filament\Admin\Pages\Concerns\ScopesLedgerReport;
use App\Services\Accounting\LedgerReportPdfService;
use App\Services\Accounting\LedgerReportService;
use App\Services\Reports\ComparativeStatementService;
use App\Services\Reports\ReportCsvExporter;
use App\Support\ReportPreferences;
use App\Support\StatementGroups;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * قائمة الدخل — Income Statement (P&L): revenue − expenses = net profit, for a
 * fiscal year, per property or consolidated.
 */
class IncomeStatement extends Page implements DeliverableReport, HasSchemas, HasTable
{
    use ExportsReport;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use PostsToLedger;
    use RendersFinancialStatement;
    use SavesReportViews;
    use ScopesLedgerReport;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected string $view = 'filament.pages.ledger-report';

    protected static string $routePath = 'income-statement';

    /**
     * Which period to show beside this one — null for none (RP-06).
     *
     * A public typed scalar, so it is a report PARAMETER: it reaches the URL, a saved view and a
     * scheduled delivery like every other. It is also remembered per user, because unlike a date it
     * says how this operator reads a statement rather than which moment they wanted.
     */
    public ?string $comparison = null;

    public function mount(): void
    {
        $this->hydrateLedgerScopeFromQuery();

        $requested = (string) request()->query('comparison', '');
        $this->comparison = in_array($requested, ComparativeStatementService::BASES, true) ? $requested : null;

        // After the query string, so an explicit ?comparison= still wins — same rule as every other
        // report parameter.
        ReportPreferences::restore($this);
    }

    /**
     * The ledger scope, plus this statement's own comparison picker.
     *
     * Appended rather than replaced so the property and period controls stay identical to the other
     * financial statements — the whole point of the shared bar (RP-02).
     */
    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(['sm' => 2, 'lg' => 4])
                ->schema([
                    ...$this->ledgerFilterComponents(),
                    Select::make('comparison')
                        ->label(__('admin.reports.comparison'))
                        ->options([
                            ComparativeStatementService::BUDGET => __('admin.reports.comparison_budget'),
                            ComparativeStatementService::PRIOR_PERIOD => __('admin.reports.comparison_prior_period'),
                            ComparativeStatementService::PRIOR_YEAR => __('admin.reports.comparison_prior_year'),
                        ])
                // Null is a real choice, not an absence: a single-period statement is the default
                // and the one most operators want most of the time.
                        ->placeholder(__('admin.reports.comparison_none'))
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(fn ($livewire) => ReportPreferences::remember($livewire)),
                ]),
        ]);
    }

    public function getTitle(): string
    {
        return __('admin.reports.income_statement_title');
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
                    $pdf = $svc->incomeStatement(
                        $this->scopedAssetIds(),
                        $this->periodStart(),
                        $this->periodEnd(),
                        $this->propertyLabel(),
                        $this->periodLabel(),
                    );

                    return response()->streamDownload(
                        fn () => print ($pdf),
                        $svc->filename('income-statement', $this->periodSlug()),
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
            ...$this->exportActions(),
        ];
    }

    public function getSubheading(): ?string
    {
        return $this->ledgerLastSyncedSubheading();
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.income_statement');
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
        $comparative = $this->comparative();

        // The comparison travels with the export. A statement an operator reads with prior-period
        // columns and then exports WITHOUT them is a different document under the same name — and
        // the export is the copy that gets emailed, filed and argued over.
        if ($comparative !== null) {
            return [
                'filename' => "income-statement-{$this->periodSlug()}-vs-{$comparative['prior_from']}",
                'headers' => [
                    __('admin.reports.section'),
                    __('admin.tables.ledger_account.code'),
                    __('admin.tables.ledger_account.account'),
                    __('admin.fields.amount'),
                    __('admin.reports.prior'),
                    __('admin.reports.change'),
                    __('admin.reports.change_pct'),
                ],
                'rows' => collect($this->comparativeRecords($comparative))
                    ->map(fn (array $r): array => [
                        $r['section'],
                        $r['code'] ?? '',
                        $r['account'],
                        $r['amount'],
                        $r['prior'],
                        $r['change'],
                        // Empty, not 0, when there is no prior figure to divide by — a spreadsheet
                        // would total a 0 and read it as "no change".
                        $r['change_pct'] === null ? '' : round((float) $r['change_pct'], 1),
                    ])
                    ->all(),
            ];
        }

        $csv = app(ReportCsvExporter::class)->incomeStatement($this->report());

        return [
            'filename' => "income-statement-{$this->periodSlug()}",
            'headers' => $csv['headers'],
            'rows' => $csv['rows'],
        ];
    }

    /**
     * The statement, with its comparison when one was asked for.
     *
     * `ComparativeStatementService` existed, was tested, and was called by NOTHING but its own test
     * — a comparative income statement that no operator could reach. This is the wiring it was
     * missing rather than a new calculation.
     *
     * @return array<string, mixed>|null
     */
    public function comparative(): ?array
    {
        if (! in_array($this->comparison, ComparativeStatementService::BASES, true)) {
            return null;
        }

        return app(ComparativeStatementService::class)->incomeStatement(
            // `periodStart()`/`periodEnd()` hand back a mutable Carbon; the service works in
            // CarbonImmutable because it derives a second span from these and must not mutate the
            // first one doing it.
            CarbonImmutable::instance($this->periodStart()),
            CarbonImmutable::instance($this->periodEnd()),
            $this->scopedAssetIds(),
            $this->comparison,
        );
    }

    protected function report(): array
    {
        return app(LedgerReportService::class)->incomeStatement(
            $this->scopedAssetIds(),
            $this->periodStart(),
            $this->periodEnd(),
        );
    }

    /**
     * The comparative statement as table records.
     *
     * Deliberately the SAME record shape as `statementRecords()` — section, account, amount, total
     * flag — with `prior` and `change` added. The alternative, a second table, would mean two
     * renderings of one statement drifting apart in exactly the way a comparison exists to prevent.
     *
     * @param  array<string, mixed>  $comparative
     * @return array<int, array<string, mixed>>
     */
    public function comparativeRecords(array $comparative): array
    {
        $sections = [
            'revenue' => [__('admin.reports.revenue'), __('admin.reports.total_revenue'), 'revenue'],
            'expense' => [__('admin.reports.expenses'), __('admin.reports.total_expenses'), 'expense'],
        ];

        $locale = app()->getLocale();
        $records = [];
        $i = 0;

        foreach ($sections as $key => [$label, $totalLabel, $totalsKey]) {
            $sectionRows = array_values(array_filter($comparative['rows'], fn (array $row) => $row['section'] === $key));

            // The same chart grouping the plain statement gets (EG-28). This path carries codes
            // rather than account ids — the comparative service compares two periods and never
            // reads the chart — which `StatementGroups` resolves either way, so one checkbox
            // cannot leave the screen disagreeing with itself about how it is laid out.
            // `current`, not `amount`: a comparative row carries two figures and neither is named
            // the way a plain statement names its one. Defaulting silently summed nothing and the
            // subtotals all read 0.00.
            $groups = StatementGroups::for($sectionRows, amountKey: 'current');
            $grouped = StatementGroups::worthShowing($groups);

            foreach ($groups as $group) {
                foreach ($group['rows'] as $row) {
                    $records[] = [
                        'id' => 'c'.$i++,
                        'section' => $label,
                        'code' => $row['code'],
                        'account' => $row['label'],
                        'amount' => $row['current'],
                        'prior' => $row['prior'],
                        'change' => $row['change'],
                        'change_pct' => $row['change_pct'],
                        'is_total' => false,
                        'is_subtotal' => false,
                        // Drills into the general ledger exactly as the plain statement does. The
                        // service used to drop the account id on the floor, so a comparison was the
                        // one reading of this statement whose figures could not be opened.
                        'account_id' => $row['account_id'] ?? null,
                    ];
                }

                if (! $grouped || ! $group['show_subtotal']) {
                    continue;
                }

                // A subtotal compares too, or the group would be the one line on a comparative
                // statement with nothing to compare it against.
                $priorTotal = round(array_sum(array_map(fn (array $r): float => (float) ($r['prior'] ?? 0), $group['rows'])), 2);
                $change = round($group['total'] - $priorTotal, 2);

                $records[] = [
                    'id' => 'c'.$i++,
                    'section' => $label,
                    'code' => null,
                    'account' => __('admin.reports.group_subtotal', [
                        'group' => $locale === 'ar' ? $group['name_ar'] : $group['name_en'],
                    ]),
                    'amount' => round($group['total'], 2),
                    'prior' => $priorTotal,
                    'change' => $change,
                    // Null, not 0%, against a zero prior — the same rule the column formats by. A
                    // rise from nothing has no percentage.
                    'change_pct' => $priorTotal == 0.0 ? null : round($change / abs($priorTotal) * 100, 1),
                    'is_total' => false,
                    'is_subtotal' => true,
                    'account_id' => null,
                ];
            }

            $total = $comparative['totals'][$totalsKey];

            $records[] = [
                'id' => 'c'.$i++,
                'section' => $label,
                'code' => null,
                'account' => $totalLabel,
                'amount' => $total['current'],
                'prior' => $total['prior'],
                'change' => $total['change'],
                'change_pct' => $total['change_pct'],
                'is_total' => true,
                'is_subtotal' => false,
                'account_id' => null,
            ];
        }

        $net = $comparative['totals']['net'];

        $records[] = [
            'id' => 'c'.$i++,
            'section' => __('admin.reports.net_profit'),
            'code' => null,
            'account' => __('admin.reports.net_profit'),
            'amount' => $net['current'],
            'prior' => $net['prior'],
            'change' => $net['change'],
            'change_pct' => $net['change_pct'],
            'is_total' => true,
            'is_subtotal' => false,
            'account_id' => null,
        ];

        return $records;
    }

    public function table(Table $table): Table
    {
        return $this->statementTable($table, comparative: $this->comparison !== null)
            ->records(function (): array {
                $comparative = $this->comparative();

                if ($comparative !== null) {
                    return $this->comparativeRecords($comparative);
                }

                $report = $this->report();

                return $this->statementRecords([
                    __('admin.reports.revenue') => [
                        'rows' => $report['revenue'],
                        'total' => $report['total_revenue'],
                        'total_label' => __('admin.reports.total_revenue'),
                    ],
                    __('admin.reports.expenses') => [
                        'rows' => $report['expense'],
                        'total' => $report['total_expense'],
                        'total_label' => __('admin.reports.total_expenses'),
                    ],
                    // Net profit stands as its own one-line section so it reads
                    // where an income statement puts it — under the two it derives
                    // from — instead of being tacked onto expenses.
                    __('admin.reports.net_profit') => [
                        'rows' => collect(),
                        'total' => $report['net_profit'],
                        'total_label' => __('admin.reports.net_profit'),
                    ],
                ]);
            });
    }
}
