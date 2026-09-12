<?php

namespace App\Filament\Admin\Pages;

use App\Contracts\DeliverableReport;
use App\Filament\Actions\GuideAction;
use App\Filament\Actions\OpenRecordAction;
use App\Filament\Admin\Pages\Concerns\ExportsReport;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Filament\Admin\Resources\VendorBills\VendorBillResource;
use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\Reports\ApAgingService;
use App\Support\AgingBuckets;
use App\Support\Modules;
use App\Support\ReportFilters;
use App\Support\ResourceLink;
use App\Support\TenantScope;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Aged payables — whom we owe, how late, and whether the bills agree with the books.
 *
 * The mirror of `ArCollections`: one row per supplier, their open bills split across the same
 * ageing buckets the receivables use, worst first. Every figure comes from `ApAgingService`, which
 * reads the SAME population `billing:reconcile` ties the payables control account to — so the
 * subheading can say, in one line, whether the two agree today. A payables report that could not be
 * checked against the control account beside it would be a list, not a report.
 *
 * Fed through `records()` because the rows are aggregates per supplier, not a row set; the ordering
 * is the service's (deepest bucket, then size) and the table does not re-sort it.
 */
class ApAging extends Page implements DeliverableReport, HasSchemas, HasTable
{
    use ExportsReport;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use SavesReportViews;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected string $view = 'filament.pages.ledger-report';

    protected static string $routePath = 'ap-aging';

    /** The day the payables are aged at (`Y-m-d`). */
    public string $asOf;

    private ?Collection $rows = null;

    public static function canAccess(): bool
    {
        // Reports AND the payables module: a report on supplier bills belongs to the module that
        // records them, so switching Vendors off takes the ageing with it — the AR pages need only
        // the reports flag because invoices have no switch of their own.
        return Modules::enabled('reports')
            && Modules::enabled('vendors')
            && (Auth::user()?->can('reports.view') ?? false);
    }

    public function mount(): void
    {
        $this->asOf = ArAging::parseAsOf(request()->query('asOf'))->toDateString();
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(['sm' => 2, 'lg' => 3])
                ->schema([
                    ReportFilters::asOf(fn () => $this->rows = null),
                ]),
        ]);
    }

    public function getTitle(): string
    {
        return __('admin.reports.ap_aging.title');
    }

    /**
     * How much is owed to how many, aged at which day — and, for TODAY's reading, whether the bills
     * agree with the payables control account. A back-dated ageing reads bills at their current
     * balance (a payment since has already moved it), so a tie-out there would compare a
     * reconstructed day against a live ledger and report a delta that is really the calendar.
     */
    public function getSubheading(): ?string
    {
        $rows = $this->rows();
        $asOf = ArAging::parseAsOf($this->asOf);

        $total = round((float) $rows->sum('total'), 2);

        $parts = [trans_choice('admin.reports.ap_aging.subheading', $rows->count(), [
            'vendors' => $rows->count(),
            'total' => 'EGP '.number_format($total, 2),
            'as_of' => $asOf->format('d/m/Y'),
        ])];

        // Against the total THIS page prints, on the reconciler's own tolerance — a ⚠ here must name
        // the two figures the reader can see, and a delta the reconcile command passes must not
        // read as a failure on the page beside it.
        if ($asOf->isSameDay(CarbonImmutable::now()) && ($gl = $this->controlBalance()) !== null) {
            $delta = round($gl - $total, 2);

            $parts[] = abs($delta) <= BooksReconciliationService::EPS
                ? __('admin.reports.ap_aging.ties_out')
                : __('admin.reports.ap_aging.does_not_tie', [
                    'gl' => 'EGP '.number_format($gl, 2),
                    'bills' => 'EGP '.number_format($total, 2),
                    'delta' => 'EGP '.number_format($delta, 2),
                ]);
        }

        return implode(' · ', $parts);
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.reports.ap_aging.nav_label');
    }

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
            $this->saveViewAction(),
            ...$this->exportActions(),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function rows(): Collection
    {
        return $this->rows ??= app(ApAgingService::class)->byVendor(ArAging::parseAsOf($this->asOf));
    }

    /** The payables control account, read for the same properties `TenantScope::applyTo()` narrowed the bills to. */
    protected function controlBalance(): ?float
    {
        return app(ApAgingService::class)->controlBalance(TenantScope::visibleAssetIds());
    }

    /**
     * The report as CSV, callable without a browser — see App\Contracts\DeliverableReport.
     *
     * The export action and scheduled delivery both go through this, so an emailed copy is
     * byte-for-byte the report an operator would have downloaded.
     */
    public function reportCsv(): array
    {
        $asOf = ArAging::parseAsOf($this->asOf)->toDateString();

        return [
            'filename' => "ap-aging-{$asOf}",
            'headers' => [
                __('admin.reports.ap_aging.vendor'),
                ...array_map(fn (string $k) => AgingBuckets::label($k), array_keys(AgingBuckets::all())),
                __('admin.reports.ap_aging.total_owed'),
                __('admin.reports.ap_aging.bills'),
                __('admin.reports.ap_aging.oldest_days'),
                __('admin.reports.ap_aging.last_payment'),
            ],
            'rows' => $this->rows()->map(fn (array $r): array => [
                $r['vendor']?->name ?? '—',
                ...array_values($r['buckets']),
                $r['total'],
                $r['bill_count'],
                $r['oldest_days'],
                $r['last_payment_at'] ? CarbonImmutable::parse($r['last_payment_at'])->toDateString() : '',
            ])->all(),
        ];
    }

    public function table(Table $table): Table
    {
        $bucketColumns = collect(array_keys(AgingBuckets::all()))
            ->map(fn (string $key) => TextColumn::make("buckets.{$key}")
                // Derived from the configured boundaries, so a column can never be headed
                // "1–30 days" while the classifier is bucketing at 45.
                ->label(AgingBuckets::label($key))
                ->money('EGP')
                ->alignEnd()
                ->color($key === 'd_90_plus' ? 'danger' : ($key === AgingBuckets::CURRENT ? 'gray' : null))
                // An empty bucket is BLANK, not "EGP 0.00": five columns of zeroes per row bury the
                // one figure the eye is scanning for, and every ageing report on the market leaves
                // an empty bucket empty. The CSV keeps the 0.00 — a spreadsheet wants a number.
                ->state(fn (array $record): ?float => (float) $record['buckets'][$key] > 0 ? (float) $record['buckets'][$key] : null)
                ->placeholder('—'))
            ->all();

        return $table
            ->records(fn (): Collection => $this->rows())
            ->paginated([25, 50, 'all'])
            ->columns([
                TextColumn::make('vendor')
                    ->label(__('admin.reports.ap_aging.vendor'))
                    ->weight('medium')
                    ->state(fn (array $record): string => $record['vendor']?->name ?? '—')
                    ->description(fn (array $record): string => trans_choice(
                        'admin.reports.ap_aging.bill_count',
                        $record['bill_count'],
                        ['count' => $record['bill_count']],
                    )),
                ...$bucketColumns,
                TextColumn::make('total')
                    ->label(__('admin.reports.ap_aging.total_owed'))
                    ->money('EGP')
                    ->weight('bold')
                    // No summarizer: these are computed rows, not a query. The total is in the
                    // subheading, beside the tie-out it is compared against.
                    ->alignEnd(),
                TextColumn::make('oldest_days')
                    ->label(__('admin.reports.ap_aging.oldest_days'))
                    ->alignEnd()
                    ->badge()
                    ->color(fn (array $record): string => match (true) {
                        $record['oldest_days'] > 90 => 'danger',
                        $record['oldest_days'] > 30 => 'warning',
                        $record['oldest_days'] > 0 => 'info',
                        default => 'gray',
                    })
                    ->state(fn (array $record): string => $record['oldest_days'] > 0
                        ? (string) $record['oldest_days']
                        : '—'),
                TextColumn::make('last_payment_at')
                    ->label(__('admin.reports.ap_aging.last_payment'))
                    ->state(fn (array $record): ?string => $record['last_payment_at']
                        ? CarbonImmutable::parse($record['last_payment_at'])->format('d/m/Y')
                        : null)
                    ->placeholder(__('admin.reports.ap_aging.never_paid'))
                    ->color(fn (array $record): ?string => $record['last_payment_at'] ? null : 'danger'),
            ])
            ->recordActions([
                // The bills behind the row, pre-filtered to this supplier's unpaid ones — the
                // register is where a bill is paid (its payments tab), so this is a LINK into it
                // rather than a second payment form with a second set of guards.
                Action::make('openBills')
                    ->label(__('admin.reports.ap_aging.open_bills'))
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->color('gray')
                    ->visible(fn (): bool => VendorBillResource::canViewAny())
                    ->url(fn (array $record): string => ResourceLink::index(
                        VendorBillResource::class,
                        ['vendor_id' => ['value' => $record['vendor_id']]],
                        tab: 'unpaid',
                    )),
            ])
            // Edit-then-view PER RECORD, null where the role may open neither — `VendorResource` has
            // no View page, so a `viewer` holding `vendors.view` alone was being linked into a 403.
            ->recordUrl(fn (array $record): ?string => OpenRecordAction::urlFor(VendorResource::class, $record['vendor']))
            ->emptyStateIcon(Heroicon::OutlinedInboxStack)
            ->emptyStateHeading(__('admin.reports.ap_aging.empty'))
            ->emptyStateDescription(__('admin.reports.ap_aging.empty_hint'));
    }
}
