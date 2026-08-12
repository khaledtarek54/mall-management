<?php

namespace App\Filament\Admin\Pages;

use App\Contracts\DeliverableReport;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Services\Reports\ReportService;
use App\Support\ReportFilters;
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

/**
 * Trading performance — MTD, YTD, MAT and like-for-like (RR-05).
 *
 * **MAT (the trailing twelve months) is the number retail runs on.** A calendar-year figure says
 * nothing useful in March and swings around Ramadan and the school year; twelve months strips the
 * seasonality out so two dates are comparable.
 *
 * **The headline growth figure and the like-for-like one are both shown, deliberately.** They
 * answer different questions and a GM needs both: total MAT growth says how the centre's income is
 * moving, LFL says how the tenants who were already there are trading. A mall that let ten new
 * shops shows growth on the first and flat on the second, and the gap between them is the story.
 */
class SalesAnalytics extends Page implements DeliverableReport, HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;
    use SavesReportViews;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.pages.ledger-report';

    protected static string $routePath = 'sales-analytics';

    /** The month the trailing twelve months end at (`Y-m-d`). */
    public string $asOf;

    private ?array $report = null;

    public static function canAccess(): bool
    {
        return Modules::enabled('reports') && (Auth::user()?->can('reports.view') ?? false);
    }

    public function mount(): void
    {
        $this->asOf = ArAging::parseAsOf(request()->query('asOf'))->endOfMonth()->toDateString();
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(['sm' => 2, 'lg' => 3])
                ->schema([
                    ReportFilters::asOf(fn () => $this->report = null)
                        ->helperText(__('admin.sales_analytics.as_of_help')),
                ]),
        ]);
    }

    public function getTitle(): string
    {
        return __('admin.sales_analytics.title');
    }

    public function getSubheading(): ?string
    {
        $r = $this->report();
        $pct = fn (?float $v) => $v === null ? '—' : number_format($v, 1).'%';

        return __('admin.sales_analytics.subheading', [
            'mat' => 'EGP '.number_format($r['mat'], 0),
            'growth' => $pct($r['mat_growth_pct']),
            'lfl' => $pct($r['lfl_growth_pct']),
            'lfl_leases' => $r['lfl_leases'],
            'to' => CarbonImmutable::parse($this->asOf)->format('m/Y'),
        ]);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.leasing');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.sales_analytics.nav_label');
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

    /** @return array<string, mixed> */
    protected function report(): array
    {
        return $this->report ??= app(ReportService::class)->salesAnalytics(
            CarbonImmutable::parse($this->asOf),
            TenantScope::currentAssetId(),
        );
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function rows(): Collection
    {
        return $this->report()['rows'];
    }

    /**
     * The report as CSV, callable without a browser — see App\Contracts\DeliverableReport.
     *
     * The export action and scheduled delivery both go through this, so an emailed copy is
     * byte-for-byte the report an operator would have downloaded.
     */
    public function reportCsv(): array
    {
        $headers = [
            __('admin.tables.invoice.unit'), __('admin.tables.invoice.tenant'),
            __('admin.tables.lease.reference'),
            __('admin.sales_analytics.mtd'), __('admin.sales_analytics.ytd'),
            __('admin.sales_analytics.mat'), __('admin.sales_analytics.prior_mat'),
            __('admin.sales_analytics.growth'), __('admin.sales_analytics.lfl_eligible'),
        ];

        $rows = $this->rows()->map(fn (array $r): array => [
            $r['unit'], $r['tenant'], $r['reference'],
            $r['mtd'], $r['ytd'], $r['mat'], $r['prior_mat'], $r['mat_growth_pct'],
            $r['lfl_eligible'] ? __('admin.occupancy_cost.estimated_yes') : __('admin.occupancy_cost.estimated_no'),
        ])->all();

        return [
            'filename' => "sales-analytics-{$this->asOf}",
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->rows())
            ->searchable(false)
            ->paginated([25, 50, 100, 'all'])
            ->columns([
                TextColumn::make('unit')
                    ->label(__('admin.tables.invoice.unit'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('tenant')
                    ->label(__('admin.tables.invoice.tenant'))
                    ->weight('medium')
                    ->description(fn (array $record): ?string => $record['reference'])
                    ->placeholder('—'),
                TextColumn::make('mtd')
                    ->label(__('admin.sales_analytics.mtd'))
                    ->money('EGP')
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('ytd')
                    ->label(__('admin.sales_analytics.ytd'))
                    ->money('EGP')
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('mat')
                    ->label(__('admin.sales_analytics.mat'))
                    ->money('EGP')
                    ->weight('bold')
                    ->alignEnd()
                    ->description(fn (array $record): ?string => $record['has_estimates']
                        ? __('admin.occupancy_cost.includes_estimates')
                        : null),
                TextColumn::make('mat_growth_pct')
                    ->label(__('admin.sales_analytics.growth'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state !== null
                        ? ($state > 0 ? '+' : '').number_format((float) $state, 1).'%'
                        : '—')
                    ->color(fn ($state): string => match (true) {
                        $state === null => 'gray',
                        (float) $state > 0 => 'success',
                        (float) $state < -10 => 'danger',
                        (float) $state < 0 => 'warning',
                        default => 'gray',
                    })
                    ->alignEnd()
                    // A tenant with no prior year has UNKNOWN growth, not flat growth.
                    ->placeholder(__('admin.sales_analytics.no_prior_year')),
                TextColumn::make('lfl_eligible')
                    ->label(__('admin.sales_analytics.lfl_eligible'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state
                        ? __('admin.sales_analytics.lfl_in')
                        : __('admin.sales_analytics.lfl_out'))
                    ->color(fn ($state): string => $state ? 'success' : 'gray')
                    ->toggleable(),
            ])
            ->recordUrl(fn (array $record): string => LeaseResource::getUrl('edit', ['record' => $record['lease_id']]))
            ->emptyStateHeading(__('admin.sales_analytics.empty'))
            ->emptyStateDescription(__('admin.sales_analytics.empty_description'));
    }
}
