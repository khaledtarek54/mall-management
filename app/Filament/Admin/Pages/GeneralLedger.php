<?php

namespace App\Filament\Admin\Pages;

use App\Contracts\DeliverableReport;
use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Concerns\PostsToLedger;
use App\Filament\Admin\Pages\Concerns\ExportsReport;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Filament\Admin\Pages\Concerns\ScopesLedgerReport;
use App\Models\LedgerAccount;
use App\Services\Accounting\LedgerReportService;
use App\Services\Reports\ReportCsvExporter;
use App\Support\Filament\PropertyField;
use App\Support\ReportPreferences;
use App\Support\SourceDocumentUrl;
use BackedEnum;
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

    protected static ?int $navigationSort = 23;

    protected string $view = 'filament.pages.ledger-report';

    protected static string $routePath = 'general-ledger';

    public ?int $accountId = null;

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

        $accountId = request()->query('accountId');

        if (filled($accountId) && is_numeric($accountId)) {
            // Clamped to what this operator may actually read: the id arrives in a URL, and a
            // general ledger of an account outside their properties is exactly what property
            // isolation exists to refuse. `account()` re-checks it too — this is the friendly half.
            $this->accountId = LedgerAccount::whereKey((int) $accountId)->value('id');
        }
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
                    ->columns(['sm' => 2, 'lg' => 5])
                    ->schema([
                        Select::make('accountId')
                            ->label(__('admin.reports.account'))
                            ->options(fn (): array => LedgerAccount::postableOptions(activeOnly: false))
                            ->placeholder(__('admin.reports.choose_account'))
                            ->searchable()
                            ->native(false)
                            ->live()
                            // Remembering happens HERE rather than through ReportFilters, because this picker is
                            // exempt from the shared component (see ReportFilters::EXEMPT) — the
                            // exemption is about the CONTROL, not about whether the choice is worth
                            // keeping. Wired at the only other place it can be.
                            ->afterStateUpdated(fn ($livewire) => ReportPreferences::remember($livewire))
                            ->columnSpan(['lg' => 2]),
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
            // The GL had NO export at all — yet it is the raw transaction detail an accountant
            // reconciles against, the report they most want in a spreadsheet. Enabled once an
            // account is selected (there is nothing to export otherwise).
            ...$this->exportActions(),
        ];
    }

    /**
     * Closing balance leads the subheading — on a كشف حساب that is the figure
     * being looked up, and it previously needed a hand-built header block.
     */
    public function getSubheading(): ?string
    {
        $sync = $this->ledgerLastSyncedSubheading();

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
        $account = $this->account();

        // A ledger with no account chosen is not an empty report — it is an unanswered question.
        // `abort(404)` was right for a click; a scheduled delivery needs a refusal it can REPORT,
        // so the operator learns their saved view is missing an account instead of receiving an
        // empty file every month and assuming there were no entries.
        if ($account === null) {
            throw new \DomainException(__('admin.reports.general_ledger_needs_account'));
        }

        $csv = app(ReportCsvExporter::class)->generalLedger($this->statement());

        return [
            'filename' => "general-ledger-{$account->code}-{$this->periodSlug()}",
            'headers' => $csv['headers'],
            'rows' => $csv['rows'],
        ];
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
                if (! $this->account()) {
                    return new LengthAwarePaginator([], 0, 50, $page);
                }

                $statement = $this->statement();

                // The opening balance is a real line of the statement, not
                // chrome: without it the first running balance looks wrong.
                $records = [[
                    'id' => 'opening',
                    'entry_date' => null,
                    'entry_number' => null,
                    'description' => __('admin.reports.opening_balance'),
                    'debit' => null,
                    'credit' => null,
                    'running_balance' => $statement['opening'],
                    'is_opening' => true,
                ]];

                foreach ($statement['lines']->values() as $i => $line) {
                    $records[] = [
                        'id' => 'l'.$i,
                        'entry_date' => $line->entry_date,
                        'entry_number' => $line->entry_number,
                        'description' => $locale === 'ar'
                            ? ($line->description_ar ?: $line->description_en)
                            : ($line->description_en ?: $line->description_ar),
                        'debit' => (float) $line->debit > 0 ? (float) $line->debit : null,
                        'credit' => (float) $line->credit > 0 ? (float) $line->credit : null,
                        'running_balance' => $line->running_balance,
                        'is_opening' => false,
                        // The other end of the trail. A ledger whose numbers cannot be opened is
                        // correct and terminal; this is what makes "what is this line made of?" a
                        // click rather than a search.
                        'source_url' => SourceDocumentUrl::forSource($line->source_type, $line->source_id),
                    ];
                }

                $total = count($records);

                // "All" is a legitimate choice (printing a full statement); it
                // is still returned AS a paginator so this closure has one
                // return type rather than a paginator-or-array union.
                $perPage = $recordsPerPage === 'all' ? max($total, 1) : (int) $recordsPerPage;

                // Not preserve_keys: each row already carries its own `id`,
                // which is what Filament keys an array record by.
                return new LengthAwarePaginator(
                    array_slice($records, ($page - 1) * $perPage, $perPage),
                    $total,
                    $perPage,
                    $page,
                );
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
                    ->color(fn (array $record): ?string => $record['is_opening'] ? 'gray' : null),
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
            ->emptyStateHeading(fn (): string => $this->account()
                ? __('admin.reports.no_movements')
                : __('admin.reports.choose_account'))
            ->emptyStateDescription(fn (): string => $this->account()
                ? __('admin.reports.no_movements_hint')
                : __('admin.reports.choose_account_hint'));
    }
}
