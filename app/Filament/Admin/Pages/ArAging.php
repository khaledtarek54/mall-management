<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Models\Asset;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Unit;
use App\Services\Reports\ReportCsvExporter;
use App\Services\Reports\ReportService;
use App\Support\Modules;
use App\Support\ReportCsv;
use BackedEnum;
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
class ArAging extends Page implements HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;

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

    public function mount(): void
    {
        $requested = (string) request()->query('bucket', 'd_1_30');

        // Only a known bucket — an unknown one makes the service throw.
        $this->bucket = array_key_exists($requested, self::buckets()) ? $requested : 'd_1_30';
    }

    /** @return array<string, string> */
    public static function buckets(): array
    {
        return [
            'current' => __('admin.widgets.ar_aging.current'),
            'd_1_30' => __('admin.widgets.ar_aging.d_1_30'),
            'd_31_60' => __('admin.widgets.ar_aging.d_31_60'),
            'd_61_90' => __('admin.widgets.ar_aging.d_61_90'),
            'd_90_plus' => __('admin.widgets.ar_aging.d_90_plus'),
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
            .' · '.$invoices->count().' '.__('admin.widgets.ar_aging.invoices');
    }

    protected function getHeaderActions(): array
    {
        return [
            // AR aging is the collections worklist — who owes what, how late. It had no export;
            // now it exports the current bucket's invoices to CSV so an operator can chase them.
            Action::make('export_csv')
                ->label(__('admin.reports.csv.export'))
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn () => Auth::user()?->can('reports.view') ?? false)
                ->authorize(fn () => Auth::user()?->can('reports.view') ?? false)
                ->action(function () {
                    $csv = app(ReportCsvExporter::class)->arAging($this->invoices());

                    return ReportCsv::stream("ar-aging-{$this->bucket}", $csv['headers'], $csv['rows']);
                }),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.accounting');
    }

    /** @return Collection<int, Invoice> */
    protected function invoices()
    {
        return app(ReportService::class)->arAgingDrilldown($this->bucket);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => $this->invoices())
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
                    ->state(fn (Invoice $record): int => max(0, (int) $record->due_date->startOfDay()->diffInDays(now()->startOfDay(), false)))
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
            ->paginated(false)
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading(__('admin.reports.no_invoices_in_bucket'));
    }
}
