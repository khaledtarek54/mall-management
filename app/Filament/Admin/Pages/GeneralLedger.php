<?php

namespace App\Filament\Admin\Pages;

use App\Contracts\DeliverableReport;
use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Concerns\PostsToLedger;
use App\Filament\Admin\Pages\Concerns\ExportsReport;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Filament\Admin\Pages\Concerns\ScopesLedgerReport;
use App\Models\LedgerAccount;
use App\Services\Accounting\LedgerReportPdfService;
use App\Services\Accounting\LedgerReportService;
use App\Services\Reports\ReportCsvExporter;
use App\Support\Filament\PropertyField;
use App\Support\JournalNarrative;
use App\Support\ReportPreferences;
use App\Support\SourceDocumentUrl;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * دفتر الأستاذ — General-ledger statement (كشف حساب) for one account: every
 * posted line in date order with a running balance, plus opening and closing.
 *
 * The running balance is accumulated in order by the report service, so this
 * table is fed through `records()` and left UNSORTED on purpose — re-ordering
 * these rows would leave each line showing a balance that does not follow from
 * the line above it.
 *
 * It does paginate, though. Splitting is safe where re-ordering is not:
 * accountLedger() accumulates running_balance across the whole ordered set
 * before anything slices it, so a row carries its correct balance on any page.
 */
class GeneralLedger extends Page implements DeliverableReport, HasSchemas, HasTable
{
    use ExportsReport;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use PostsToLedger;
    use SavesReportViews;
    use ScopesLedgerReport;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected string $view = 'filament.pages.ledger-report';

    protected static string $routePath = 'general-ledger';

    public ?int $accountId = null;

    /**
     * Every account with movement in the period, each with its opening, lines and closing — the
     * month-end review and the auditor's request (the reports audit, 2026-09-12).
     *
     * The page answered one account at a time, so the GL for a period was ~40 exports. A public
     * bool, so a saved view ("GL — every account, monthly") and the reader's remembered shape both
     * carry it; the account picker is ignored while it is on rather than cleared, so switching back
     * lands on the account the operator was reading.
     */
    public bool $allAccounts = false;

    /**
     * Open on the account, year and property a statement row was clicked from.
     *
     * `ScopesLedgerReport::mount()` sets the year to today and nothing else, so without this a
     * drill-down link landed on an empty page headed "choose an account" — which is worse than no
     * link, because the operator has to rebuild the filters they just came from.
     */
    public function mount(): void
    {
        $this->hydrateLedgerScopeFromQuery();

        // A saved view or a hub link writes `allAccounts=1` (`ReportParameters::urlFor()` writes
        // every declared parameter), and `ReportPreferences::restore()` deliberately leaves a key
        // the URL names alone — so if nothing here read it, the headline saved view ("GL, every
        // account, monthly") opened with the toggle OFF and a "choose an account" empty state.
        // Found by review, by opening the link.
        if (request()->query->has('allAccounts')) {
            $this->allAccounts = filter_var(request()->query('allAccounts'), FILTER_VALIDATE_BOOLEAN);
        }

        $accountId = request()->query('accountId');

        if (filled($accountId) && is_numeric($accountId)) {
            // Clamped to what this operator may actually read: the id arrives in a URL, and a
            // general ledger of an account outside their properties is exactly what property
            // isolation exists to refuse. `account()` re-checks it too — this is the friendly half.
            $this->accountId = LedgerAccount::whereKey((int) $accountId)->value('id');

            // A link that names an account and NOT the toggle asks for THAT account. The reader's
            // remembered shape has just been restored, and with "every account" on the picker is
            // ignored — so a statement row's drill-down would have landed on the whole ledger, forty
            // accounts deep, instead of the one that was clicked. The URL beats the memory here as
            // it does for every other remembered parameter; a saved view that states both is
            // honoured as saved.
            if (! request()->query->has('allAccounts')) {
                $this->allAccounts = false;
            }
        }

        // The remembered (or saved-view) shape decides how the first render groups.
        $this->applyGrouping();
    }

    public function getTitle(): string
    {
        return __('admin.reports.general_ledger_title');
    }

    /** Account picker, in front of the shared year + property strip. */
    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(['sm' => 2, 'lg' => 6])
                    ->schema([
                        Select::make('accountId')
                            ->label(__('admin.reports.account'))
                            ->options(fn (): array => LedgerAccount::postableOptions(activeOnly: false))
                            ->placeholder(__('admin.reports.choose_account'))
                            ->searchable()
                            ->native(false)
                            ->live()
                            // Not asked while every account is on: the toggle beside it says so.
                            ->disabled(fn (): bool => $this->allAccounts)
                            // Remembering happens HERE rather than through ReportFilters, because this picker is
                            // exempt from the shared component (see ReportFilters::EXEMPT) — the
                            // exemption is about the CONTROL, not about whether the choice is worth
                            // keeping. Wired at the only other place it can be.
                            ->afterStateUpdated(fn ($livewire) => ReportPreferences::remember($livewire))
                            ->columnSpan(['lg' => 2]),
                        Toggle::make('allAccounts')
                            ->label(__('admin.reports.every_account'))
                            ->helperText(__('admin.reports.every_account_help'))
                            ->inline(false)
                            ->live()
                            ->afterStateUpdated(fn ($livewire) => ReportPreferences::remember($livewire)),
                        Select::make('year')
                            ->label(__('admin.reports.fiscal_year'))
                            ->options(fn (): array => $this->yearOptions())
                            ->native(false)
                            ->live(),
                        // This page declares its own filter strip (it adds the account picker), so
                        // the trait's period select has to be repeated here rather than inherited.
                        Select::make('period')
                            ->label(__('admin.reports.period'))
                            ->options(fn (): array => $this->periodOptions())
                            ->placeholder(__('admin.reports.full_year'))
                            ->native(false)
                            ->live(),
                        // Pinned — same component, same reasoning, as the shared strip in
                        // ScopesLedgerReport. This page repeats the controls only because it adds
                        // an account picker beside them.
                        PropertyField::reportScope(
                            afterStateUpdated: fn ($livewire) => ReportPreferences::remember($livewire),
                        ),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
            $this->saveViewAction(),
            $this->postToLedgerAction(),
            // The GL was the one ledger report with no printed form — the four statements and the
            // trial balance print, and the detail behind them could only be exported. One account's
            // statement, or every account in the period, on the same template as the screen.
            Action::make('download_pdf')
                ->label(__('admin.actions.download_pdf'))
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn (): bool => $this->canViewReports() && $this->hasSubject())
                ->authorize(fn (): bool => $this->canViewReports() && $this->hasSubject())
                ->action(function () {
                    $svc = app(LedgerReportPdfService::class);
                    $account = $this->allAccounts ? null : $this->account();

                    $pdf = $svc->generalLedger(
                        $this->scopedAssetIds(),
                        $this->periodStart(),
                        $this->periodEnd(),
                        $this->propertyLabel(),
                        $this->periodLabel(),
                        account: $account,
                    );

                    return response()->streamDownload(
                        fn () => print ($pdf),
                        $svc->filename('general-ledger'.($account ? '-'.$account->code : '-all'), $this->periodSlug()),
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
            // The GL had NO export at all — yet it is the raw transaction detail an accountant
            // reconciles against, the report they most want in a spreadsheet. Enabled once an
            // account is selected (there is nothing to export otherwise).
            ...$this->exportActions(),
        ];
    }

    /**
     * The table groups by account only while every account is on. `$tableGrouping` is Filament's
     * own "which declared group applies" property, and it is set HERE — in the request that flips
     * the toggle — and at mount. Not through a conditional `groups()`: Filament builds the table at
     * BOOT, with the properties as hydrated, before any update hook runs, so a group declared only
     * while the toggle is on did not exist on the request that turned it on. Measured: that shape
     * rendered grouped on a dev database only because the toggle had been REMEMBERED before mount,
     * and ungrouped the moment a person flipped it on the page.
     *
     * The page is reset too: forty accounts on page 3, then one account of two pages, is a page 3
     * of 2 — an empty table under "no movements in this period" about an account with sixty lines.
     */
    public function updatedAllAccounts(): void
    {
        $this->applyGrouping();
        $this->resetPage();
    }

    /** A new account is a new statement — it starts on its first page. */
    public function updatedAccountId(): void
    {
        $this->resetPage();
    }

    private function applyGrouping(): void
    {
        $this->tableGrouping = $this->allAccounts ? 'account' : null;
    }

    /** Is there anything to show — an account chosen, or every account asked for? */
    protected function hasSubject(): bool
    {
        return $this->allAccounts || $this->account() !== null;
    }

    /**
     * Closing balance leads the subheading — on a كشف حساب that is the figure
     * being looked up, and it previously needed a hand-built header block. Over every account it
     * is the count instead: forty closing balances are the table, not a sentence.
     */
    public function getSubheading(): ?string
    {
        $sync = $this->ledgerLastSyncedSubheading();

        if ($this->allAccounts) {
            $count = trans_choice('admin.reports.accounts_with_movement', $this->statements()->count(), ['count' => $this->statements()->count()]);

            return $sync ? $count.' · '.$sync : $count;
        }

        if (! $this->account()) {
            return $sync;
        }

        $closing = __('admin.reports.closing_balance').': EGP '.number_format($this->statement()['closing'], 2);

        return $sync ? $closing.' · '.$sync : $closing;
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.general_ledger');
    }

    protected function account(): ?LedgerAccount
    {
        return $this->accountId ? LedgerAccount::find($this->accountId) : null;
    }

    /**
     * This page is ONE account's movements, so a portfolio-wide count of unallocated entries beside
     * it answers a question the reader did not ask — and reads as though that account were missing
     * money it never had.
     */
    protected function unallocatedAccountId(): ?int
    {
        // Over every account the report spans the whole ledger, so the portfolio-wide count is the
        // right population.
        return $this->allAccounts ? null : $this->account()?->id;
    }

    /**
     * Everything up to the period's end, not the period alone — the trial balance's rule, for the
     * trial balance's reason: the OPENING balance is an *as at* figure, so what this ledger is
     * missing is every unallocated entry dated up to the end, not only the window's. (Until
     * 2026-09-12 the GL kept the default window while its docblock claimed parity with the trial
     * balance; the same month on the two pages counted differently whenever a null-asset entry
     * predated the period.)
     *
     * @return array{0: ?Carbon, 1: Carbon}
     */
    protected function unallocatedRange(): array
    {
        return [null, $this->periodEnd()];
    }

    /**
     * …and with NO account chosen there is no statement to be missing anything FROM.
     *
     * Returning null from `unallocatedAccountId()` does not suppress the notice — null is the
     * WIDEST population, the portfolio-wide count every other statement gets — so the unanswered
     * page rendered an empty table under a warning saying *"They are NOT in the figures above"*
     * about figures that do not exist. `reportCsv()` refuses first (a scheduled delivery needs a
     * refusal it can report), so it was the screen alone.
     */
    protected function unallocatedNoticeApplies(): bool
    {
        return $this->hasSubject();
    }

    /**
     * Every account with movement or a standing balance in the window, in chart order — memoised,
     * because the subheading, the table and the CSV each ask and the answer is a query per account.
     *
     * @return Collection<int, array{account: LedgerAccount, opening: float, lines: Collection, closing: float}>
     */
    protected function statements(): Collection
    {
        return $this->statements ??= app(LedgerReportService::class)->generalLedger(
            $this->scopedAssetIds(),
            $this->periodStart(),
            $this->periodEnd(),
        );
    }

    /**
     * Per request only: private and never hydrated, so it is born at render — after every update
     * hook has run — and dies with the request. Nothing has to reset it.
     */
    private ?Collection $statements = null;

    /** @return array{opening: float, lines: Collection, closing: float} */
    protected function statement(): array
    {
        $account = $this->account();

        if (! $account) {
            return ['opening' => 0.0, 'lines' => collect(), 'closing' => 0.0];
        }

        return app(LedgerReportService::class)->accountLedger(
            $account,
            $this->scopedAssetIds(),
            $this->periodStart(),
            $this->periodEnd(),
        );
    }

    /**
     * The report as CSV, callable without a browser — see App\Contracts\DeliverableReport.
     *
     * The export action and scheduled delivery both go through this, so an emailed copy is
     * byte-for-byte the report an operator would have downloaded.
     */
    public function reportCsv(): array
    {
        if ($this->allAccounts) {
            return $this->withUnallocatedNotice([
                'filename' => "general-ledger-all-{$this->periodSlug()}",
                ...app(ReportCsvExporter::class)->generalLedgerAll($this->statements()),
            ]);
        }

        $account = $this->account();

        // A ledger with no account chosen is not an empty report — it is an unanswered question.
        // `abort(404)` was right for a click; a scheduled delivery needs a refusal it can REPORT,
        // so the operator learns their saved view is missing an account instead of receiving an
        // empty file every month and assuming there were no entries.
        if ($account === null) {
            throw new \DomainException(__('admin.reports.general_ledger_needs_account'));
        }

        $csv = app(ReportCsvExporter::class)->generalLedger($this->statement());

        return $this->withUnallocatedNotice([
            'filename' => "general-ledger-{$account->code}-{$this->periodSlug()}",
            'headers' => $csv['headers'],
            'rows' => $csv['rows'],
        ]);
    }

    public function table(Table $table): Table
    {
        $locale = app()->getLocale();

        return $table
            // A records()-backed table does NOT paginate itself: Filament hands
            // the closure `page` + `recordsPerPage` and expects it to slice.
            // ->paginated() alone rendered all 411 lines of one account-year as
            // a 24,000px page.
            ->records(function (int $page, int|string $recordsPerPage) use ($locale): LengthAwarePaginator {
                if (! $this->hasSubject()) {
                    return new LengthAwarePaginator([], 0, 50, $page);
                }

                $records = [];

                // One account, or every account — the SAME rows per account, so an account's page
                // in the full ledger reads exactly as its own statement does. Over every account
                // each is bracketed by its opening and its closing (grouped under its heading by
                // the table); on one account the closing is the subheading, as it always was.
                $statements = $this->allAccounts
                    ? $this->statements()
                    : collect([['account' => $this->account()] + $this->statement()]);

                foreach ($statements as $statement) {
                    $records = [...$records, ...$this->accountRecords($statement, $locale, closingRow: $this->allAccounts)];
                }

                $total = count($records);

                // "All" is a legitimate choice (printing a full statement); it
                // is still returned AS a paginator so this closure has one
                // return type rather than a paginator-or-array union.
                $perPage = $recordsPerPage === 'all' ? max($total, 1) : (int) $recordsPerPage;

                // The document behind each line is resolved for the rows on THIS page only.
                // `SourceDocumentUrl::forSource()` loads the document to ask whether the reader may
                // open it — measured over a demo year with every account on: 677 of 758 queries
                // per render were those loads, for 1,837 rows of which 50 were shown, on every
                // pagination click. The other end of the trail is still one click away; it is
                // simply not paid for on rows nobody is looking at.
                $shown = array_slice($records, ($page - 1) * $perPage, $perPage);

                foreach ($shown as &$record) {
                    if (isset($record['source_type'])) {
                        $record['source_url'] = SourceDocumentUrl::forSource($record['source_type'], $record['source_id']);
                    }
                }
                unset($record);

                // Not preserve_keys: each row already carries its own `id`,
                // which is what Filament keys an array record by.
                return new LengthAwarePaginator($shown, $total, $perPage, $page);
            })
            ->columns([
                TextColumn::make('entry_date')
                    ->label(__('admin.fields.entry_date'))
                    ->formatStateUsing(fn ($state): string => $state ? Carbon::parse($state)->format('d/m/Y') : '')
                    ->placeholder(''),
                TextColumn::make('entry_number')
                    ->url(fn (array $record): ?string => $record['source_url'] ?? null)
                    ->color(fn (array $record): ?string => ($record['source_url'] ?? null) ? 'primary' : null)
                    ->label(__('admin.tables.journal_entry.number'))
                    ->fontFamily('mono')
                    ->size('sm')
                    ->placeholder(''),
                TextColumn::make('description')
                    ->label(__('admin.fields.description'))
                    ->wrap()
                    ->color(fn (array $record): ?string => $record['is_opening'] ? 'gray' : null)
                    ->weight(fn (array $record): ?string => ($record['is_closing'] ?? false) ? 'bold' : null),
                TextColumn::make('debit')
                    ->label(__('admin.fields.debit'))
                    ->money('EGP')
                    ->alignEnd()
                    ->placeholder('—'),
                TextColumn::make('credit')
                    ->label(__('admin.fields.credit'))
                    ->money('EGP')
                    ->alignEnd()
                    ->placeholder('—'),
                TextColumn::make('running_balance')
                    ->label(__('admin.reports.running_balance'))
                    ->money('EGP')
                    ->alignEnd()
                    ->weight('bold'),
            ])
            // Over every account, the rows sit under their account's heading — the same native
            // grouping the statements use for their sections. Array records, so the key and the
            // title come off the record rather than a query. DECLARED unconditionally and APPLIED
            // through `$tableGrouping` (see `updatedAllAccounts()`): Filament builds this table at
            // boot with the properties as hydrated, so a group declared only when the toggle is on
            // did not exist on the request that turned it on. The toolbar's group control is hidden
            // — the toggle is the control, and offering "group by account" on one account would be
            // a heading over the whole page saying what the picker already says.
            ->groups([
                Group::make('account')
                    ->label(__('admin.reports.account'))
                    ->getKeyFromRecordUsing(fn (array $record): string => $record['account_code'])
                    ->getTitleFromRecordUsing(fn (array $record): string => $record['account_code'].' — '.$record['account_name']),
            ])
            ->groupingSettingsHidden()
            // Paginated, but never re-sorted. Order carries meaning here, so the
            // rows must not be re-ordered — but they can safely be SPLIT:
            // accountLedger() accumulates running_balance over the whole ordered
            // set before anything slices it, so every row carries its correct
            // balance whichever page it lands on.
            //
            // It does have to paginate. One account-year of demo data is 400+
            // lines — a 24,000px page — and a real mall's AR control account
            // over a full year is far longer. The closing balance is in the
            // sub-heading, so the figure being looked up is always in view.
            ->paginated([50, 100, 250, 'all'])
            ->defaultPaginationPageOption(50)
            ->emptyStateIcon('heroicon-o-book-open')
            ->emptyStateHeading(fn (): string => $this->hasSubject()
                ? __('admin.reports.no_movements')
                : __('admin.reports.choose_account'))
            ->emptyStateDescription(fn (): string => $this->hasSubject()
                ? __('admin.reports.no_movements_hint')
                : __('admin.reports.choose_account_hint'));
    }

    /**
     * One account's statement as table rows: the opening line, every posted line with its running
     * balance and the document behind it, and — when asked — the closing line that brackets it.
     *
     * @param  array{account: LedgerAccount, opening: float, lines: Collection, closing: float}  $statement
     * @return list<array<string, mixed>>
     */
    private function accountRecords(array $statement, string $locale, bool $closingRow): array
    {
        $account = $statement['account'];
        $prefix = 'a'.$account->id.'-';
        $meta = [
            'account_code' => $account->code,
            'account_name' => $locale === 'ar' ? ($account->name_ar ?: $account->name_en) : ($account->name_en ?: $account->name_ar),
        ];

        // The opening balance is a real line of the statement, not
        // chrome: without it the first running balance looks wrong.
        $records = [[
            'id' => $prefix.'opening',
            'entry_date' => null,
            'entry_number' => null,
            'description' => __('admin.reports.opening_balance'),
            'debit' => null,
            'credit' => null,
            'running_balance' => $statement['opening'],
            'is_opening' => true,
            'is_closing' => false,
        ] + $meta];

        foreach ($statement['lines']->values() as $i => $line) {
            $records[] = [
                'id' => $prefix.'l'.$i,
                'entry_date' => $line->entry_date,
                'entry_number' => $line->entry_number,
                // A query row, not a model — so it resolves through the same seam the
                // accessor uses rather than re-deriving the locale rule here (EG-36).
                'description' => JournalNarrative::resolve(
                    $line->description_key ?? null,
                    isset($line->description_data) ? json_decode((string) $line->description_data, true) : null,
                    $line->description_en,
                    $line->description_ar,
                    $locale,
                ),
                'debit' => (float) $line->debit > 0 ? (float) $line->debit : null,
                'credit' => (float) $line->credit > 0 ? (float) $line->credit : null,
                'running_balance' => $line->running_balance,
                'is_opening' => false,
                'is_closing' => false,
                // The other end of the trail. A ledger whose numbers cannot be opened is
                // correct and terminal; this is what makes "what is this line made of?" a
                // click rather than a search. Carried as the pair here and resolved to a URL
                // for the page's own rows in the records closure — see the note there.
                'source_type' => $line->source_type,
                'source_id' => $line->source_id,
            ] + $meta;
        }

        if ($closingRow) {
            $records[] = [
                'id' => $prefix.'closing',
                'entry_date' => null,
                'entry_number' => null,
                'description' => __('admin.reports.closing_balance'),
                'debit' => null,
                'credit' => null,
                'running_balance' => $statement['closing'],
                'is_opening' => false,
                'is_closing' => true,
            ] + $meta;
        }

        return $records;
    }
}
