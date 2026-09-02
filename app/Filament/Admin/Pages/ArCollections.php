<?php

namespace App\Filament\Admin\Pages;

use App\Contracts\DeliverableReport;
use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Pages\Concerns\ExportsReport;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use App\Services\Reports\ReportService;
use App\Services\TenantStatementPdfService;
use App\Support\AgingBuckets;
use App\Support\Filament\PdfDownloadAction;
use App\Support\Modules;
use App\Support\ReportFilters;
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
    use ExportsReport;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use SavesReportViews;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoneArrowUpRight;

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
                    ReportFilters::asOf(fn () => $this->rows = null),
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

    public static function getNavigationLabel(): string
    {
        return __('admin.collections.nav_label');
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
                // The END of the chase (UX5-03). The worklist told you who to call and then left you
                // to find the payment form yourself — six screens, re-searching the tenant you were
                // already looking at. This carries the tenant across, so recording what they just
                // said they paid is: click, type the amount, save (the amount field suggests the
                // allocation oldest-first on blur).
                //
                // A LINK, not a modal: the real payment form guards the posting date, the property
                // scope, over-allocation and the orphaned-receipt case, and a second slimmed-down
                // form beside it would be a second set of those guards to keep in step.
                Action::make('recordPayment')
                    ->label(__('admin.collections.record_payment'))
                    ->icon('heroicon-o-banknotes')
                    ->color('gray')
                    ->visible(fn (): bool => Auth::user()?->can('payments.create') ?? false)
                    ->url(fn (array $record): ?string => $record['tenant_id']
                        ? PaymentResource::getUrl('create', ['for_tenant' => $record['tenant_id']])
                        : null),
                // The chase itself: the statement is what you attach to the call or the email.
                PdfDownloadAction::make('statement')
                    ->label(__('admin.collections.download_statement'))
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->visible(fn (): bool => Auth::user()?->can('reports.download') ?? false)
                    ->authorize(fn (): bool => Auth::user()?->can('reports.download') ?? false)
                    // This table's rows are ARRAYS, not models, so the tenant is resolved from the
                    // row on both hooks rather than type-hinted.
                    ->recipient(fn (array $record) => Tenant::find($record['tenant_id']))
                    ->document(function (array $record, string $locale): string {
                        $tenant = Tenant::find($record['tenant_id']);
                        abort_unless($tenant !== null, 404);

                        // Scoped to what this user may see — a statement is a document about a
                        // tenant, and a restricted user must not assemble one across properties
                        // they cannot read.
                        return app(TenantStatementPdfService::class)
                            ->build($tenant, TenantScope::visibleAssetIds(), null, null, $locale);
                    })
                    ->filename(function (array $record): string {
                        $tenant = Tenant::find($record['tenant_id']);
                        abort_unless($tenant !== null, 404);

                        return app(TenantStatementPdfService::class)->filename($tenant);
                    }),
            ])
            ->recordUrl(fn (array $record): ?string => $record['tenant']
                ? TenantResource::getUrl('edit', ['record' => $record['tenant_id']])
                : null)
            ->emptyStateHeading(__('admin.collections.empty'));
    }
}
