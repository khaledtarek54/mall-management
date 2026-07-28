<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\MonthlyCloseStats;
use App\Services\Reports\MonthlyCloseReportPdfService;
use App\Services\Reports\ReportService;
use App\Support\Modules;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
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
use Illuminate\Support\Facades\Auth;

/**
 * Monthly close — the month's billing, collections and AR position.
 *
 * The KPI grid and the ageing buckets are a native Stats widget
 * (MonthlyCloseStats); revenue by type is a native table. Both replace
 * hand-written markup that carried its own font sizes and hex colours and so
 * never followed the panel theme or dark mode.
 */
class Reports extends Page implements HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.pages.ledger-report';

    public string $period;

    public function mount(): void
    {
        $this->period = request()->query('period', CarbonImmutable::now()->format('Y-m'));
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(['sm' => 2, 'lg' => 3])
                    ->schema([
                        Select::make('period')
                            ->label(__('admin.reports.period'))
                            ->options(fn (): array => $this->lastNPeriods(12))
                            ->native(false)
                            ->live(),
                    ]),
            ]);
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.reports.nav_label');
    }

    public function getTitle(): string
    {
        return __('admin.reports.page_title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.accounting');
    }

    public static function canAccess(): bool
    {
        // Module flag AND per-user permission (audit M18 F-68 / D-53).
        return Modules::enabled('reports')
            && Auth::user()?->can('reports.view');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * The month's KPI + ageing cards.
     *
     * Declared as a schema rendered inside the page body — NOT via `getHeaderWidgets()`.
     * Filament's page component renders header widgets itself, above the slot; registering
     * them there *and* rendering them in the view printed every card twice, and would put
     * the cards above the period picker that drives them.
     */
    public function statsWidgets(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema($this->getWidgetsSchemaComponents([MonthlyCloseStats::class])),
        ]);
    }

    /**
     * @return array<string, mixed>
     *
     * Filament passes this to every widget it renders. It used to be spelled
     * `getHeaderWidgetsData()`, which Filament 4 never calls — so the picker moved the
     * revenue table while the cards stayed pinned to the current month.
     */
    public function getWidgetData(): array
    {
        return ['period' => $this->period];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download_monthly_close')
                ->label(__('admin.reports.download_monthly_close_pdf'))
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => Auth::user()?->can('reports.download') ?? false)
                ->authorize(fn (): bool => Auth::user()?->can('reports.download') ?? false)
                ->action(fn () => $this->downloadMonthlyClose()),
            Action::make('ar_aging')
                ->label(__('admin.reports.ar_aging_drilldown'))
                ->icon('heroicon-o-arrow-trending-down')
                ->color('gray')
                ->url(fn (): string => ArAging::getUrl()),
        ];
    }

    public function downloadMonthlyClose()
    {
        abort_unless(
            Auth::user()?->can('reports.download') ?? false,
            403,
        );

        $period = $this->resolvePeriod();
        $svc = app(MonthlyCloseReportPdfService::class);
        $pdf = $svc->build($period);

        return response()->streamDownload(
            fn () => print ($pdf),
            $svc->filename($period),
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                $report = app(ReportService::class)->monthlyClose($this->resolvePeriod());

                $records = [];
                foreach ($report['revenue_by_type'] as $type => $amount) {
                    $records[] = [
                        'id' => $type,
                        'type' => __("admin.enums.invoice_item_type.{$type}"),
                        'amount' => round((float) $amount, 2),
                    ];
                }

                return $records;
            })
            ->heading(__('admin.reports.revenue_by_type'))
            ->columns([
                TextColumn::make('type')
                    ->label(__('admin.fields.type'))
                    ->weight('medium'),
                TextColumn::make('amount')
                    ->label(__('admin.fields.amount'))
                    ->money('EGP')
                    ->alignEnd()
                    ->summarize(
                        Summarizer::make('total')
                            ->label(__('admin.reports.totals'))
                            ->money('EGP')
                            ->using(fn (): float => round(
                                (float) array_sum(app(ReportService::class)->monthlyClose($this->resolvePeriod())['revenue_by_type']),
                                2,
                            ))
                    ),
            ])
            ->paginated(false)
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateHeading(__('admin.reports.no_movements'));
    }

    private function resolvePeriod(): CarbonImmutable
    {
        return self::parsePeriod($this->period);
    }

    /**
     * Parse a `Y-m` period, falling back to the current month.
     *
     * Public + static because MonthlyCloseStats parses the SAME string: if the
     * two ever disagreed, the KPI cards and the revenue table on this page would
     * describe different months without saying so.
     */
    public static function parsePeriod(?string $period): CarbonImmutable
    {
        try {
            // !Y-m, not Y-m: with no day in the format, Carbon fills it from TODAY. On the 29th–31st that overflows a shorter month — "2026-02" parsed on the 29th becomes 1 March — so the period silently shifts by a month. The `!` resets every unspecified field, giving midnight on the 1st.
            return CarbonImmutable::createFromFormat('!Y-m', (string) $period)->startOfMonth();
        } catch (\Throwable) {
            return CarbonImmutable::now()->startOfMonth();
        }
    }

    /** @return array<string,string> */
    private function lastNPeriods(int $n): array
    {
        $out = [];
        $cur = CarbonImmutable::now()->startOfMonth();
        for ($i = 0; $i < $n; $i++) {
            $key = $cur->format('Y-m');
            $out[$key] = $cur->locale(app()->getLocale())->isoFormat('MMMM YYYY');
            $cur = $cur->subMonth();
        }

        return $out;
    }
}
