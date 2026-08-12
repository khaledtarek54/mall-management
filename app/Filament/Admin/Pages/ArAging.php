<?php

namespace App\Filament\Admin\Pages;

use App\Contracts\DeliverableReport;
use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Pages\Concerns\ExportsReport;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Models\Asset;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Unit;
use App\Services\Reports\ReportCsvExporter;
use App\Services\Reports\ReportService;
use App\Support\AgingBuckets;
use App\Support\Modules;
use App\Support\ReportFilters;
use App\Support\ReportPreferences;
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
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * AR aging drill-down — the collections worklist: which invoices sit in a given
 * lateness bucket, who owes them and how late they are.
 *
 * Fed through `records()` rather than `query()` because the bucket boundaries are
 * computed in PHP by ReportService (whole-day math shared with the summary
 * widget). Reusing that call is what guarantees the drill-down can never show a
 * different set of invoices than the bucket total on the dashboard counted.
 */
class ArAging extends Page implements DeliverableReport, HasSchemas, HasTable
{
    use ExportsReport;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use SavesReportViews;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingDown;

    protected static bool $shouldRegisterNavigation = false; // reached via the Reports page

    public static function canAccess(): bool
    {
        // Module flag AND per-user permission (audit M18 F-68 / D-53).
        return Modules::enabled('reports')
            && Auth::user()?->can('reports.view');
    }

    protected string $view = 'filament.pages.ledger-report';

    protected static string $routePath = 'ar-aging';

    public string $bucket = 'd_1_30';

    /**
     * The day the receivables are aged at (`Y-m-d`).
     *
     * Carried in from the monthly-close stat card that was clicked: that card ages at the
     * period being closed, so re-ageing here at "now" listed a different set of invoices
     * than the bucket total counted. Defaults to today when the page is opened directly.
     */
    public string $asOf;

    public function mount(): void
    {
        $requested = (string) request()->query('bucket', 'd_1_30');

        // Only a known bucket — an unknown one makes the service throw.
        $this->bucket = array_key_exists($requested, self::buckets()) ? $requested : 'd_1_30';
        $this->asOf = self::parseAsOf(request()->query('asOf'))->toDateString();

        ReportPreferences::restore($this);
    }

    /** Parse a client-supplied `Y-m-d`, falling back to today. */
    public static function parseAsOf(mixed $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', (string) $value)->endOfDay();
        } catch (\Throwable) {
            return CarbonImmutable::now()->endOfDay();
        }
    }

    /** @return array<string, string> */
    public static function buckets(): array
    {
        return [
            // Derived from the configured boundaries, so a tab can never be labelled "1–30 days"
            // while the classifier is bucketing at 45. See App\Support\AgingBuckets.
            ...AgingBuckets::labels(),
        ];
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(['sm' => 2, 'lg' => 3])
                    ->schema([
                        Select::make('bucket')
                            ->label(__('admin.reports.bucket'))
                            ->options(fn (): array => self::buckets())
                            ->native(false)
                            ->live(),
                        // The ageing date is part of the answer, not a hidden constant:
                        // "31–60 days" only means something relative to a day. Showing it
                        // makes the drill-down reconcilable against the card that opened it.
                        // No cache to clear — this page queries fresh through the table, unlike the
                        // reports that memoise `$rows`. The empty closure says that out loud rather
                        // than leaving the reader to wonder which kind of page this is.
                        ReportFilters::asOf(fn () => null),
                    ]),
            ]);
    }

    public function getTitle(): string
    {
        return __('admin.reports.ar_aging_page_title');
    }

    /** How much is sitting in this bucket — the number a collections call is prioritised by. */
    public function getSubheading(): ?string
    {
        $invoices = $this->invoices();

        return __('admin.reports.bucket_total').': EGP '.number_format((float) $invoices->sum('balance'), 2)
            .' · '.$invoices->count().' '.__('admin.widgets.ar_aging.invoices')
            .' · '.__('admin.reports.aged_as_of').' '.self::parseAsOf($this->asOf)->format('d/m/Y');
    }

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
            $this->saveViewAction(),
            // AR aging is the collections worklist — who owes what, how late. It had no export;
            // now it exports the current bucket's invoices to CSV so an operator can chase them.
            ...$this->exportActions(),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.accounting');
    }

    /** @return Collection<int, Invoice> */
    protected function invoices()
    {
        return app(ReportService::class)->arAgingDrilldown($this->bucket, self::parseAsOf($this->asOf));
    }

    /**
     * The report as CSV, callable without a browser — see App\Contracts\DeliverableReport.
     *
     * The export action and scheduled delivery both go through this, so an emailed copy is
     * byte-for-byte the report an operator would have downloaded.
     */
    public function reportCsv(): array
    {
        $csv = app(ReportCsvExporter::class)->arAging($this->invoices());

        // The as-of date is in the filename: an exported worklist is only
        // reconcilable if you can tell which day it was aged at.
        $asOf = self::parseAsOf($this->asOf)->toDateString();

        return [
            'filename' => "ar-aging-{$this->bucket}-{$asOf}",
            'headers' => $csv['headers'],
            'rows' => $csv['rows'],
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            // Slices in the closure — a records()-backed table does not
            // paginate itself (Filament passes page + recordsPerPage in and
            // expects the closure to do it).
            ->records(function (int $page, int|string $recordsPerPage) {
                $invoices = $this->invoices();

                if ($recordsPerPage === 'all') {
                    return $invoices;
                }

                return new LengthAwarePaginator(
                    $invoices->forPage($page, (int) $recordsPerPage),
                    $invoices->count(),
                    (int) $recordsPerPage,
                    $page,
                );
            })
            ->columns([
                TextColumn::make('number')
                    ->label(__('admin.tables.invoice.number'))
                    ->fontFamily('mono')
                    ->size('sm'),
                TextColumn::make('tenant.name')
                    ->label(__('admin.tables.invoice.tenant'))
                    ->weight('medium')
                    ->placeholder('—'),
                TextColumn::make('lease.unit.code')
                    ->label(__('admin.tables.invoice.unit'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('due_date')
                    ->label(__('admin.tables.invoice.due_date'))
                    ->date('d/m/Y'),
                TextColumn::make('days_overdue')
                    ->label(__('admin.reports.days_overdue'))
                    ->alignEnd()
                    // Measured against the ageing date, not "now" — otherwise a row could
                    // read "12 days" inside the 31–60 bucket it was correctly placed in.
                    ->state(fn (Invoice $record): int => max(0, (int) $record->due_date
                        ->startOfDay()
                        ->diffInDays(self::parseAsOf($this->asOf)->startOfDay(), false)))
                    // Past 60 days is where a receivable stops being late and
                    // starts being a problem.
                    ->color(fn ($state): string => $state > 60 ? 'danger' : 'warning')
                    ->weight('medium'),
                TextColumn::make('balance')
                    ->label(__('admin.tables.invoice.balance'))
                    ->money('EGP')
                    ->alignEnd()
                    ->weight('bold')
                    ->color('danger')
                    ->summarize(
                        Summarizer::make('total')
                            ->label(__('admin.reports.bucket_total'))
                            ->money('EGP')
                            ->using(fn (): float => round((float) $this->invoices()->sum('balance'), 2))
                    ),
            ])
            // Straight to the invoice — this page exists to start a chase, and
            // the old markup needed a bespoke "View →" anchor to do it.
            // An invoice carries no asset_id — its property is reached through
            // lease.unit (already eager-loaded by the report service, so no N+1).
            // Reading $record->asset_id here silently produced NO link at all.
            ->recordUrl(function (Invoice $record): ?string {
                /** @var Lease|null $lease */
                $lease = $record->lease;

                /** @var Unit|null $unit */
                $unit = $lease?->unit;

                /** @var Asset|null $asset */
                $asset = $unit?->asset;

                return $asset
                    ? InvoiceResource::getUrl('edit', ['record' => $record], tenant: $asset)
                    : null;
            })
            // Paginated: a bucket is unbounded — 90+ days on a mall in trouble
            // is a long list. Safe to split because the bucket total in the
            // sub-heading and the column summarizer are both computed over the
            // WHOLE bucket, not the visible page, so the figure a collections
            // run is prioritised by never changes as you page through it.
            ->paginated([25, 50, 100, 'all'])
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading(__('admin.reports.no_invoices_in_bucket'));
    }
}
