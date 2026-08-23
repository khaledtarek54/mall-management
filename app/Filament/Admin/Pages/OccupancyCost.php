<?php

namespace App\Filament\Admin\Pages;

use App\Contracts\DeliverableReport;
use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Pages\Concerns\ExportsReport;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Services\Reports\ReportService;
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
 * Occupancy cost as a percentage of sales (RR-04).
 *
 * **The number that says who is in trouble before they miss a payment.** A fashion tenant paying
 * 12% of turnover in total occupancy cost is healthy; one paying 30% is failing, and will usually
 * stop paying before they say anything. Atriom already held every input — invoices and
 * `TenantSalesDeclaration` — and produced the number nowhere, which is why the benchmark called
 * this the best value-per-line item in the whole document.
 *
 * The healthy band differs by trade (food courts run high, anchors run low), so the thresholds here
 * are a **starting point for conversation**, not a verdict: 20% amber, 25% red are the commonly
 * cited retail rules of thumb. An operator who wants Eltizam's own bands gets them from a setting;
 * nobody has asked yet, and inventing the numbers into a settings screen would imply a precision
 * this does not have.
 */
class OccupancyCost extends Page implements DeliverableReport, HasSchemas, HasTable
{
    use ExportsReport;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use SavesReportViews;

    /** Commonly cited retail rules of thumb — a prompt to look, not a verdict. */
    public const AMBER_PCT = 20.0;

    public const RED_PCT = 25.0;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected string $view = 'filament.pages.ledger-report';

    protected static string $routePath = 'occupancy-cost';

    /** Window start (`Y-m-d`). Defaults to a rolling 12 months. */
    public string $from;

    /** Window end (`Y-m-d`). */
    public string $to;

    private ?Collection $rows = null;

    public static function canAccess(): bool
    {
        return Modules::enabled('reports') && (Auth::user()?->can('reports.view') ?? false);
    }

    public function mount(): void
    {
        $to = ArAging::parseAsOf(request()->query('to'))->endOfMonth();

        $this->to = $to->toDateString();
        $this->from = $to->subMonths(11)->startOfMonth()->toDateString();
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(['sm' => 2, 'lg' => 3])
                ->schema([
                    ReportFilters::from(fn () => $this->rows = null),
                    ReportFilters::to(fn () => $this->rows = null)
                        ->helperText(__('admin.occupancy_cost.window_help')),
                ]),
        ]);
    }

    public function getTitle(): string
    {
        return __('admin.occupancy_cost.title');
    }

    public function getSubheading(): ?string
    {
        $rows = $this->rows();
        $cost = (float) $rows->sum('occupancy_cost');
        $sales = (float) $rows->sum('declared_sales');

        return __('admin.occupancy_cost.subheading', [
            'tenants' => $rows->count(),
            // Portfolio ratio is total cost over total sales — NOT the mean of the per-tenant
            // ratios, which would let one tiny tenant with no sales dominate the headline.
            'portfolio' => $sales > 0 ? number_format($cost / $sales * 100, 1).'%' : '—',
            'over' => $rows->filter(fn (array $r) => ($r['occupancy_cost_pct'] ?? 0) >= self::RED_PCT)->count(),
            'from' => CarbonImmutable::parse($this->from)->format('m/Y'),
            'to' => CarbonImmutable::parse($this->to)->format('m/Y'),
        ]);
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.occupancy_cost.nav_label');
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
        return $this->rows ??= app(ReportService::class)->occupancyCost(
            CarbonImmutable::parse($this->from),
            CarbonImmutable::parse($this->to),
            TenantScope::currentAssetId(),
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
        $headers = [
            __('admin.tables.invoice.unit'), __('admin.tables.invoice.tenant'),
            __('admin.tables.lease.reference'),
            __('admin.occupancy_cost.cost'), __('admin.occupancy_cost.sales'),
            __('admin.occupancy_cost.ratio'), __('admin.occupancy_cost.months_declared'),
            __('admin.occupancy_cost.estimated'),
        ];

        $rows = $this->rows()->map(fn (array $r): array => [
            $r['unit'], $r['tenant'], $r['reference'],
            $r['occupancy_cost'], $r['declared_sales'],
            $r['occupancy_cost_pct'], $r['months_declared'],
            $r['has_estimates'] ? __('admin.occupancy_cost.estimated_yes') : __('admin.occupancy_cost.estimated_no'),
        ])->all();

        return [
            'filename' => "occupancy-cost-{$this->from}-{$this->to}",
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
                TextColumn::make('occupancy_cost')
                    ->label(__('admin.occupancy_cost.cost'))
                    ->money('EGP')
                    ->alignEnd(),
                TextColumn::make('declared_sales')
                    ->label(__('admin.occupancy_cost.sales'))
                    ->money('EGP')
                    ->alignEnd()
                    // An estimated figure makes the ratio soft, and the operator should see that
                    // before acting on it rather than after.
                    ->description(fn (array $record): ?string => $record['has_estimates']
                        ? __('admin.occupancy_cost.includes_estimates')
                        : null),
                TextColumn::make('occupancy_cost_pct')
                    ->label(__('admin.occupancy_cost.ratio'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state !== null ? number_format((float) $state, 1).'%' : '—')
                    ->color(fn ($state): string => match (true) {
                        $state === null => 'gray',
                        (float) $state >= self::RED_PCT => 'danger',
                        (float) $state >= self::AMBER_PCT => 'warning',
                        default => 'success',
                    })
                    ->weight('bold')
                    ->alignEnd()
                    // No sales declared is UNKNOWN, not healthy — showing 0% would rank the tenant
                    // who files nothing as the strongest in the mall.
                    ->placeholder(__('admin.occupancy_cost.no_sales')),
                TextColumn::make('months_declared')
                    ->label(__('admin.occupancy_cost.months_declared'))
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(),
            ])
            ->recordUrl(fn (array $record): string => LeaseResource::getUrl('edit', ['record' => $record['lease_id']]))
            ->emptyStateHeading(__('admin.occupancy_cost.empty'))
            ->emptyStateDescription(__('admin.occupancy_cost.empty_description'));
    }
}
