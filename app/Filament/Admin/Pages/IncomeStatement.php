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
use App\Services\Reports\StatementSpread;
use App\Support\IncomeStatementLayout;
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
use Filament\Tables\Columns\TextColumn;
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

    /**
     * How many amount columns the statement has — null for one, `ytd` for this month beside the year
     * to date, `monthly` for the twelve months of the year (RP-07).
     *
     * A public typed scalar for the same reason `$comparison` is: it reaches the URL, a saved view
     * and a scheduled delivery like every other report parameter, and it is remembered per user
     * because it says how this operator READS a statement rather than which moment they wanted.
     */
    public ?string $spread = null;

    /** This month beside the year to date — the layout Yardi's income statement opens in. */
    public const SPREAD_YTD = 'ytd';

    /** The twelve months of the fiscal year side by side, then a full-year column. */
    public const SPREAD_MONTHLY = 'monthly';

    /** @var array<int, string> */
    public const SPREADS = [self::SPREAD_YTD, self::SPREAD_MONTHLY];

    public function mount(): void
    {
        $this->hydrateLedgerScopeFromQuery();

        $requested = (string) request()->query('comparison', '');
        $this->comparison = in_array($requested, ComparativeStatementService::BASES, true) ? $requested : null;

        $spread = (string) request()->query('spread', '');
        $this->spread = in_array($spread, self::SPREADS, true) ? $spread : null;

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
                    Select::make('spread')
                        ->label(__('admin.reports.spread'))
                        ->options([
                            self::SPREAD_YTD => __('admin.reports.spread_ytd'),
                            self::SPREAD_MONTHLY => __('admin.reports.spread_monthly'),
                        ])
                        ->placeholder(__('admin.reports.spread_none'))
                        ->native(false)
                        ->live()
                        // Month-and-year-to-date needs a month. With the whole year selected the two
                        // columns would be the same figures printed twice, which reads as an error —
                        // the rule this statement already applies to NOI and to a one-row subtotal.
                        // Said out loud rather than by silently dropping the option, because an
                        // option that vanishes reads as a bug in the screen.
                        ->helperText(fn (): ?string => $this->spread === self::SPREAD_YTD && ! $this->hasSelectedMonth()
                            ? __('admin.reports.spread_needs_a_month')
                            : null)
                        ->afterStateUpdated(fn ($livewire) => ReportPreferences::remember($livewire)),
                ]),
        ]);
    }

    /**
     * The columns this statement is read across, or null for the single-period reading.
     *
     * @return list<array{key: string, label: string, from: CarbonImmutable, to: CarbonImmutable}>|null
     */
    public function spreadGroups(): ?array
    {
        if ($this->spread === self::SPREAD_MONTHLY) {
            $groups = [];

            foreach ($this->periodOptions() as $month => $label) {
                $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfDay();

                $groups[] = ['key' => 'm'.str_replace('-', '', $month), 'label' => $label,
                    'from' => $start, 'to' => $start->endOfMonth()->endOfDay()];
            }

            // The full-year column. Not the sum of the twelve — read from the ledger like every
            // other column, so a month the fiscal year does not cover cannot make the total
            // disagree with the statement an operator gets by picking the whole year.
            $groups[] = ['key' => 'total', 'label' => __('admin.reports.spread_total'),
                'from' => CarbonImmutable::instance($this->fiscalYearStart()),
                'to' => CarbonImmutable::instance($this->fiscalYearEnd())];

            return $groups;
        }

        if ($this->spread !== self::SPREAD_YTD || ! $this->hasSelectedMonth()) {
            return null;
        }

        return [
            ['key' => 'period', 'label' => $this->periodLabel(),
                'from' => CarbonImmutable::instance($this->periodStart()),
                'to' => CarbonImmutable::instance($this->periodEnd())],
            // Year to date means from the FISCAL year's start, not 1 January — an April-to-March mall
            // year is ordinary here, and the shared scope already knows where the year begins.
            ['key' => 'ytd', 'label' => __('admin.reports.spread_period'),
                'from' => CarbonImmutable::instance($this->fiscalYearStart()),
                'to' => CarbonImmutable::instance($this->periodEnd())],
        ];
    }

    /**
     * The spread, when one was asked for AND is answerable.
     *
     * @return array<string, mixed>|null
     */
    public function spreadReport(): ?array
    {
        $groups = $this->spreadGroups();

        if ($groups === null) {
            return null;
        }

        return app(StatementSpread::class)->incomeStatement(
            $groups,
            $this->scopedAssetIds(),
            // A comparison rides along on the month-and-YTD reading — actual, budget and variance per
            // group, which IS Yardi's layout. It deliberately does NOT ride along on the twelve-month
            // one: thirty-six columns is not a statement anybody reads.
            $this->spread === self::SPREAD_YTD ? $this->comparison : null,
        );
    }

    private function hasSelectedMonth(): bool
    {
        return is_string($this->period) && preg_match('/^\d{4}-\d{2}$/', $this->period) === 1;
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
                    $spread = $this->spreadReport();

                    // The printed copy is the one that gets filed and argued over, so it prints the
                    // columns the operator is looking at. A PDF button that quietly reverted to the
                    // single-period statement would hand them a different document under the same
                    // name.
                    $pdf = $spread !== null
                        ? $svc->incomeStatementSpread(
                            $spread,
                            $this->scopedAssetIds(),
                            $this->propertyLabel(),
                            $this->periodLabel(),
                        )
                        : $svc->incomeStatement(
                            $this->scopedAssetIds(),
                            $this->periodStart(),
                            $this->periodEnd(),
                            $this->propertyLabel(),
                            $this->periodLabel(),
                        );

                    return response()->streamDownload(
                        fn () => print ($pdf),
                        $svc->filename('income-statement'.($spread !== null ? '-'.$this->spread : ''), $this->periodSlug()),
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
        $spread = $this->spreadReport();

        // The columns travel with the export, for the same reason the comparison does.
        if ($spread !== null) {
            return [
                'filename' => "income-statement-{$this->periodSlug()}-{$this->spread}",
                'headers' => [
                    __('admin.reports.section'),
                    __('admin.tables.ledger_account.code'),
                    __('admin.tables.ledger_account.account'),
                    ...array_column($spread['spans'], 'label'),
                ],
                'rows' => collect($this->spreadRecords($spread))
                    ->map(fn (array $r): array => [
                        $r['section'],
                        $r['code'] ?? '',
                        $r['account'],
                        ...array_map(fn (array $span): float => (float) ($r['a_'.$span['key']] ?? 0), $spread['spans']),
                    ])
                    ->all(),
            ];
        }

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
        $locale = app()->getLocale();
        $records = [];
        $i = 0;

        foreach ($this->comparativeLayout($comparative) as $part) {
            // A NET part is a figure the parts above it foot to — NOI, and the bottom line. It has
            // no accounts of its own, so it prints as one bold row.
            if ($part['is_net']) {
                $total = $comparative['totals'][$part['totals_key']];

                $records[] = [
                    'id' => 'c'.$i++,
                    'section' => $part['label'],
                    'code' => null,
                    'account' => $part['label'],
                    'amount' => $total['current'],
                    'prior' => $total['prior'],
                    'change' => $total['change'],
                    'change_pct' => $total['change_pct'],
                    'is_total' => true,
                    'is_subtotal' => false,
                    'account_id' => null,
                ];

                continue;
            }

            $sectionRows = array_values(array_filter(
                $comparative['rows'],
                // A null `statement_section` means "do not narrow": on an unclassified chart the
                // statement has one revenue section and one expense section, and narrowing would
                // give a stray row nowhere to print — a line silently missing from a financial
                // statement is the one failure worse than a wrong layout.
                fn (array $row): bool => $row['section'] === $part['section']
                    && ($part['statement_section'] === null || $row['statement_section'] === $part['statement_section']),
            ));

            // A below-the-line section with nothing on EITHER side prints nothing — the same rule
            // `IncomeStatementLayout` applies to the plain statement, so one picker cannot change
            // which sections the statement has.
            if ($sectionRows === [] && $part['optional']) {
                continue;
            }

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
                        'section' => $part['label'],
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
                    'section' => $part['label'],
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

            $total = $comparative['totals'][$part['totals_key']];

            $records[] = [
                'id' => 'c'.$i++,
                'section' => $part['label'],
                'code' => null,
                'account' => $part['total_label'],
                'amount' => $total['current'],
                'prior' => $total['prior'],
                'change' => $total['change'],
                'change_pct' => $total['change_pct'],
                'is_total' => true,
                'is_subtotal' => false,
                'account_id' => null,
            ];
        }

        return $records;
    }

    /**
     * The comparative statement's sections, in reading order.
     *
     * The SAME shape the plain reading uses ({@see IncomeStatementLayout::shape()}), because
     * choosing a comparison must change how many COLUMNS the statement has and never what shape it
     * IS — a picker that silently relaid the sections would leave two readings of one statement that
     * nobody could reconcile.
     *
     * It cannot call `sections()`, which attaches rows from the report's own collections: a
     * comparative row set is the UNION of two periods, and an account that ran last period and
     * stopped has no row in this one. Dropping it would hide the change most worth seeing. So the
     * rows are selected by what they CARRY, which is what `shape()` names `section` and
     * `statement_section` for.
     *
     * @param  array<string, mixed>  $comparative
     * @return list<array<string, mixed>>
     */
    private function comparativeLayout(array $comparative): array
    {
        return IncomeStatementLayout::shape((bool) ($comparative['has_below_the_line'] ?? false));
    }

    /**
     * The spread as table records — the same record shape every other reading produces, with one
     * `a_{span}` key per column instead of a single `amount`.
     *
     * Laid out from `IncomeStatementLayout::shape()` like the other two readings, so a statement read
     * across twelve months has the same sections, in the same order, as the same statement read for
     * one — a spread that relaid the sections would be a different report wearing this one's name.
     *
     * @param  array<string, mixed>  $spread
     * @return list<array<string, mixed>>
     */
    public function spreadRecords(array $spread): array
    {
        $locale = app()->getLocale();
        $records = [];
        $i = 0;

        $amounts = function (array $source, array $keys): array {
            $cells = [];

            foreach ($keys as $key) {
                $cells['a_'.$key] = round((float) ($source[$key] ?? 0), 2);
            }

            return $cells;
        };

        $keys = array_column($spread['spans'], 'key');

        foreach (IncomeStatementLayout::shape((bool) $spread['has_below_the_line']) as $part) {
            $totals = $spread['totals'][$part['totals_key']] ?? [];

            if ($part['is_net']) {
                $records[] = [
                    'id' => 's'.$i++, 'section' => $part['label'], 'code' => null,
                    'account' => $part['label'], 'is_total' => true, 'is_subtotal' => false,
                    'account_id' => null,
                ] + $amounts($totals, $keys);

                continue;
            }

            $sectionRows = array_values(array_filter(
                $spread['rows'],
                fn (array $row): bool => $row['section'] === $part['section']
                    && ($part['statement_section'] === null || $row['statement_section'] === $part['statement_section']),
            ));

            if ($sectionRows === [] && $part['optional']) {
                continue;
            }

            // The chart's own subtotals, exactly as the single-period reading gets them (EG-28).
            // `StatementGroups` totals ONE figure per row and a spread row carries several under
            // `amounts`, so its own `total` is deliberately left at zero here — the key named below
            // exists on no spread row — and each group's per-column totals are summed further down.
            $groups = StatementGroups::for($sectionRows, amountKey: 'amount');
            $grouped = StatementGroups::worthShowing($groups);

            foreach ($groups as $group) {
                foreach ($group['rows'] as $row) {
                    $records[] = [
                        'id' => 's'.$i++, 'section' => $part['label'], 'code' => $row['code'],
                        'account' => $locale === 'ar' ? $row['name_ar'] : $row['name_en'],
                        'is_total' => false, 'is_subtotal' => false,
                        'account_id' => $row['account_id'] ?? null,
                    ] + $amounts($row['amounts'], $keys);
                }

                if (! $grouped || ! $group['show_subtotal']) {
                    continue;
                }

                // Summed per COLUMN from the group's own rows. `StatementGroups` cannot do it — it
                // totals one figure per row and every column here needs its own.
                $groupTotals = [];

                foreach ($keys as $key) {
                    $groupTotals[$key] = round(array_sum(array_map(
                        fn (array $r): float => (float) ($r['amounts'][$key] ?? 0),
                        $group['rows'],
                    )), 2);
                }

                $records[] = [
                    'id' => 's'.$i++, 'section' => $part['label'], 'code' => null,
                    'account' => __('admin.reports.group_subtotal', [
                        'group' => $locale === 'ar' ? $group['name_ar'] : $group['name_en'],
                    ]),
                    'is_total' => false, 'is_subtotal' => true, 'account_id' => null,
                ] + $amounts($groupTotals, $keys);
            }

            $records[] = [
                'id' => 's'.$i++, 'section' => $part['label'], 'code' => null,
                'account' => $part['total_label'], 'is_total' => true, 'is_subtotal' => false,
                'account_id' => null,
            ] + $amounts($totals, $keys);
        }

        return $records;
    }

    /**
     * The spread's table: the shared code/account columns, then one money column per span.
     *
     * @param  array<string, mixed>  $spread
     */
    private function spreadTable(Table $table, array $spread): Table
    {
        $columns = [
            TextColumn::make('code')
                ->label(__('admin.tables.ledger_account.code'))
                ->fontFamily('mono')->size('sm')->placeholder(''),
            TextColumn::make('account')
                ->label(__('admin.tables.ledger_account.account'))
                ->weight(fn (array $record): string => $this->statementWeight($record))
                ->url(fn (array $record): ?string => $this->ledgerUrlFor($record))
                ->color(fn (array $record): ?string => $this->ledgerUrlFor($record) ? 'primary' : null),
        ];

        foreach ($spread['spans'] as $span) {
            $columns[] = TextColumn::make('a_'.$span['key'])
                ->label($span['label'])
                ->money('EGP')
                ->alignEnd()
                ->weight(fn (array $record): string => $this->statementWeight($record))
                // Colour by DIRECTION only, and only on a variance. On an income statement a rise is
                // welcome in revenue and unwelcome in expenses, and the table does not know which
                // section a reader is looking at — claiming otherwise would be worse than staying
                // neutral. The same rule the comparison column already follows.
                ->color(fn (array $record): ?string => $span['kind'] === 'variance'
                    ? match (true) {
                        ($record['a_'.$span['key']] ?? 0) > 0 => 'success',
                        ($record['a_'.$span['key']] ?? 0) < 0 => 'danger',
                        default => null,
                    }
                    : null);
        }

        return $table
            ->columns($columns)
            ->paginated(false)
            ->records(fn (): array => $this->spreadRecords($spread));
    }

    public function table(Table $table): Table
    {
        $spread = $this->spreadReport();

        if ($spread !== null) {
            return $this->spreadTable($table, $spread);
        }

        return $this->statementTable($table, comparative: $this->comparison !== null)
            ->records(function (): array {
                $comparative = $this->comparative();

                if ($comparative !== null) {
                    return $this->comparativeRecords($comparative);
                }

                // Sections and their order come from `IncomeStatementLayout`, which the CSV and the
                // PDF read too — including the net lines, which stand as their own one-line
                // sections so each reads where an income statement puts it, under what it derives
                // from, instead of being tacked onto the section above.
                return $this->statementRecords(
                    collect(IncomeStatementLayout::sections($this->report()))
                        ->mapWithKeys(fn (array $section): array => [$section['label'] => [
                            'rows' => $section['rows'],
                            'total' => $section['total'],
                            'total_label' => $section['total_label'],
                        ]])
                        ->all(),
                );
            });
    }
}
