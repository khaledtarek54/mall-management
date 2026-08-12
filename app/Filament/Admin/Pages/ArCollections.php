<?php

namespace App\Filament\Admin\Pages;

use App\Contracts\DeliverableReport;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use App\Services\Reports\ReportService;
use App\Services\TenantStatementPdfService;
use App\Support\Modules;
use App\Support\ReportCsv;
use App\Support\TenantScope;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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
use Illuminate\Support\Facades\Response;
use App\Support\AgingBuckets;

/**
 * AR collections — the worklist, not the report (UX-03).
 *
 * The existing AR Aging page answers *"how much is 31–60 days late"* and drills into the invoices
 * in one bucket. That is the accountant's question. The **collections** question is different and
 * had no screen at all: *"who do I call this morning, and about what."* One row per tenant, their
 * outstanding split across every bucket at once, worst-first — deepest bucket, then size, because a
 * tenant 120 days late for 10k needs the call before one 5 days late for 100k.
 *
 * Every number comes from `ReportService`, and specifically from the **same**
 * `ReportService::agingBucketKey()` the summary and the drill-down use — a bucket total that
 * disagrees with the list behind it destroys the operator's trust in both, so the boundary
 * arithmetic exists exactly once.
 */
class ArCollections extends Page implements DeliverableReport, HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;
    use SavesReportViews;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoneArrowUpRight;

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.ledger-report';

    protected static string $routePath = 'ar-collections';

    /** The day the receivables are aged at (`Y-m-d`). */
    public string $asOf;

    private ?Collection $rows = null;

    public static function canAccess(): bool
    {
        return Modules::enabled('reports') && (Auth::user()?->can('reports.view') ?? false);
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
                    // The ageing date is part of the answer, not a hidden constant: "90 days late"
                    // only means something relative to a day.
                    DatePicker::make('asOf')
                        ->label(__('admin.reports.aged_as_of'))
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(fn () => $this->rows = null),
                ]),
        ]);
    }

    public function getTitle(): string
    {
        return __('admin.collections.title');
    }

    public function getSubheading(): ?string
    {
        $rows = $this->rows();

        return __('admin.collections.subheading', [
            'tenants' => $rows->count(),
            'total' => 'EGP '.number_format((float) $rows->sum('total'), 2),
            'as_of' => ArAging::parseAsOf($this->asOf)->format('d/m/Y'),
        ]);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.receivables');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.collections.nav_label');
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->saveViewAction(),
            Action::make('export_csv')
                ->label(__('admin.reports.csv.export'))
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn (): bool => Auth::user()?->can('reports.view') ?? false)
                ->authorize(fn (): bool => Auth::user()?->can('reports.view') ?? false)
                ->action(function () {
                    $csv = $this->reportCsv();

                    return ReportCsv::stream($csv['filename'], $csv['headers'], $csv['rows']);
                }),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function rows(): Collection
    {
        return $this->rows ??= app(ReportService::class)
            ->arCollectionsByTenant(ArAging::parseAsOf($this->asOf));
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

        $headers = [
            __('admin.tables.invoice.tenant'),
            ...array_map(fn (string $k) => AgingBuckets::label($k), array_keys(AgingBuckets::all())),
            __('admin.collections.total_owed'),
            __('admin.collections.invoices'),
            __('admin.collections.oldest_days'),
            __('admin.collections.last_payment'),
        ];

        $rows = $this->rows()->map(fn (array $r): array => [
            $r['tenant']?->name ?? '—',
            ...array_values($r['buckets']),
            $r['total'],
            $r['invoice_count'],
            $r['oldest_days'],
            $r['last_payment_at'] ? CarbonImmutable::parse($r['last_payment_at'])->toDateString() : '',
        ])->all();

        return [
            'filename' => "ar-collections-{$asOf}",
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    public function table(Table $table): Table
    {
        $bucketColumns = collect(array_keys(AgingBuckets::all()))
            ->map(fn (string $key) => TextColumn::make("buckets.{$key}")
                ->label(__("admin.widgets.ar_aging.{$key}"))
                ->money('EGP')
                ->alignEnd()
                // The deepest bucket is the one a collections clerk scans for.
                ->color($key === 'd_90_plus' ? 'danger' : ($key === 'current' ? 'gray' : null))
                ->state(fn (array $record): float => (float) $record['buckets'][$key]))
            ->all();

        return $table
            ->records(fn (): Collection => $this->rows())
            ->paginated([25, 50, 'all'])
            ->columns([
                TextColumn::make('tenant')
                    ->label(__('admin.tables.invoice.tenant'))
                    ->weight('medium')
                    ->state(fn (array $record): string => $record['tenant']?->name ?? '—')
                    ->description(fn (array $record): string => trans_choice(
                        'admin.collections.invoice_count',
                        $record['invoice_count'],
                        ['count' => $record['invoice_count']],
                    )),
                ...$bucketColumns,
                TextColumn::make('total')
                    ->label(__('admin.collections.total_owed'))
                    ->money('EGP')
                    ->weight('bold')
                    // No Filament summarizer here: they run against a query builder and this
                    // table is computed rows, not a query. The portfolio total is in the
                    // subheading, which is where an operator looks for it anyway.
                    ->alignEnd(),
                TextColumn::make('oldest_days')
                    ->label(__('admin.collections.oldest_days'))
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
                    ->label(__('admin.collections.last_payment'))
                    // Slow payer or stopped payer — the single most useful signal on this screen.
                    ->state(fn (array $record): ?string => $record['last_payment_at']
                        ? CarbonImmutable::parse($record['last_payment_at'])->format('d/m/Y')
                        : null)
                    ->placeholder(__('admin.collections.never_paid'))
                    ->color(fn (array $record): ?string => $record['last_payment_at'] ? null : 'danger'),
            ])
            ->recordActions([
                // The chase itself: the statement is what you attach to the call or the email.
                Action::make('statement')
                    ->label(__('admin.collections.download_statement'))
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->visible(fn (): bool => Auth::user()?->can('reports.download') ?? false)
                    ->authorize(fn (): bool => Auth::user()?->can('reports.download') ?? false)
                    ->action(function (array $record) {
                        abort_unless(Auth::user()?->can('reports.download') ?? false, 403);

                        $tenant = Tenant::find($record['tenant_id']);
                        abort_unless($tenant !== null, 404);

                        $pdf = app(TenantStatementPdfService::class);
                        // Scoped to what this user may see — a statement is a document about a
                        // tenant, and a restricted user must not assemble one across properties
                        // they cannot read.
                        $content = $pdf->build($tenant, TenantScope::visibleAssetIds());

                        return Response::streamDownload(
                            fn () => print ($content),
                            $pdf->filename($tenant),
                        );
                    }),
            ])
            ->recordUrl(fn (array $record): ?string => $record['tenant']
                ? TenantResource::getUrl('edit', ['record' => $record['tenant_id']])
                : null)
            ->emptyStateHeading(__('admin.collections.empty'));
    }
}
