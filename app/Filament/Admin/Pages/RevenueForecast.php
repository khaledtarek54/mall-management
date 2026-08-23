<?php

namespace App\Filament\Admin\Pages;

use App\Contracts\DeliverableReport;
use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Pages\Concerns\ExportsReport;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Services\LeaseBillingForecastService;
use App\Services\PortfolioRevenueForecastService;
use App\Support\Modules;
use App\Support\TenantScope;
use BackedEnum;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * What the portfolio will bill, month by month — the contracted revenue forecast.
 *
 * Voyager's Forecast Manager *(cited,
 * `docs/benchmarks/yardi/01-yardi-lease-administration.md` §334)*, and §205's observation that the
 * forecast is computable the day a lease is signed — which is true here only because
 * `ChargeScheduleService` writes the whole rent ladder at signing rather than one current amount.
 *
 * **The speculative half is deliberately absent.** Voyager's forecast includes assumed renewals and
 * re-lets; that needs a renewal probability and a market rent, neither of which this system holds.
 * A guessed figure in a revenue forecast is worse than a missing one, because on the chart it is
 * indistinguishable from contracted income — and this is a page an owner may be shown. Every figure
 * here can be pointed at a signed contract, and the subheading says exactly that.
 *
 * **It computes nothing.** Each month is `LeaseBillingForecastService` summed, which is
 * `MonthlyBillingService::planInvoiceForLease()` — the method the real billing run persists. A
 * forecast with its own arithmetic disagrees with the invoices it predicts, and would do so first
 * on the cases that matter: a proration edge, a cycle boundary, an escalation step.
 */
class RevenueForecast extends Page implements DeliverableReport, HasSchemas, HasTable
{
    use ExportsReport;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use SavesReportViews;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected string $view = 'filament.pages.ledger-report';

    protected static string $routePath = 'revenue-forecast';

    /** How far ahead to look, in months. */
    public int $horizon = LeaseBillingForecastService::HORIZON_MONTHS;

    private ?array $data = null;

    public static function canAccess(): bool
    {
        return Modules::enabled('reports') && (Auth::user()?->can('reports.view') ?? false);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(['sm' => 2, 'lg' => 3])
                ->schema([
                    Select::make('horizon')
                        ->label(__('admin.revenue_forecast.horizon'))
                        ->options([
                            6 => __('admin.revenue_forecast.horizon_months', ['count' => 6]),
                            12 => __('admin.revenue_forecast.horizon_months', ['count' => 12]),
                            24 => __('admin.revenue_forecast.horizon_months', ['count' => 24]),
                            36 => __('admin.revenue_forecast.horizon_months', ['count' => 36]),
                        ])
                        ->native(false)
                        // Recomputed rather than cached: a forecast that silently predates a rent
                        // change is the failure this page exists to prevent.
                        ->afterStateUpdated(fn () => $this->data = null)
                        ->live(),
                ]),
        ]);
    }

    public function getTitle(): string
    {
        return __('admin.revenue_forecast.title');
    }

    /**
     * The subheading carries the caveat, because a chart is read before its documentation.
     *
     * It names the total, the lease count and — the part that matters — that this is CONTRACTED
     * income only, with no assumed renewals or re-lets in it.
     */
    public function getSubheading(): ?string
    {
        $data = $this->forecast();

        return __('admin.revenue_forecast.subheading', [
            'total' => number_format($data['total'], 2),
            'leases' => $data['leases'],
            'from' => $data['from'],
            'to' => $data['to'],
        ]);
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.revenue_forecast.nav_label');
    }

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
            $this->saveViewAction(),
            ...$this->exportActions(),
        ];
    }

    /** @return array<string, mixed> */
    protected function forecast(): array
    {
        return $this->data ??= app(PortfolioRevenueForecastService::class)->forecast(
            TenantScope::currentAssetId(),
            null,
            (int) $this->horizon,
        );
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function rows(): Collection
    {
        return collect($this->forecast()['months']);
    }

    /**
     * The report as CSV, callable without a browser — see App\Contracts\DeliverableReport.
     *
     * A column per charge type, because the question a finance lead asks of a forecast is not
     * "how much?" but "how much of it is rent?" — a single total cannot be reconciled against a
     * budget that is itself split by account.
     */
    public function reportCsv(): array
    {
        $data = $this->forecast();
        $types = array_keys($data['by_type']);

        $headers = [
            __('admin.revenue_forecast.month'),
            ...array_map(fn (string $t): string => __('admin.enums.invoice_item_type')[$t] ?? $t, $types),
            __('admin.revenue_forecast.total'),
            __('admin.revenue_forecast.leases'),
            __('admin.revenue_forecast.basis'),
        ];

        $rows = collect($data['months'])->map(fn (array $m): array => [
            $m['period'],
            ...array_map(fn (string $t): float => $m['by_type'][$t] ?? 0.0, $types),
            $m['total'],
            $m['leases'],
            $m['actual']
                ? __('admin.revenue_forecast.actual')
                : __('admin.revenue_forecast.projected'),
        ])->all();

        return [
            'filename' => 'revenue-forecast-'.$data['from'],
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->rows())
            ->searchable(false)
            ->paginated(false)
            ->columns([
                TextColumn::make('period')
                    ->label(__('admin.revenue_forecast.month')),

                TextColumn::make('total')
                    ->label(__('admin.revenue_forecast.total'))
                    ->money('EGP')
                    // No column summariser: this table is fed from an ARRAY (`->records()`), and
                    // Filament's summarisers run against a query builder — they receive null and
                    // fatal. The window total lives in the subheading, which is where someone
                    // looks for it anyway.
                    ->weight('bold'),

                TextColumn::make('leases')
                    ->label(__('admin.revenue_forecast.leases'))
                    ->alignEnd(),

                // Which months are settled fact and which are projection. A forecast read as a
                // fact is the whole risk of this page, so the distinction is a column rather than
                // a footnote — and a month is only ACTUAL when every lease in it has been
                // invoiced, so a part-billed month reads as a projection.
                TextColumn::make('actual')
                    ->label(__('admin.revenue_forecast.basis'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state
                        ? __('admin.revenue_forecast.actual')
                        : __('admin.revenue_forecast.projected'))
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
            ])
            ->emptyStateIcon(Heroicon::OutlinedPresentationChartLine)
            ->emptyStateHeading(__('admin.revenue_forecast.empty_heading'))
            ->emptyStateDescription(__('admin.revenue_forecast.empty_description'));
    }
}
