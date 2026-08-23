<?php

namespace App\Filament\Admin\Pages;

use App\Contracts\DeliverableReport;
use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Pages\Concerns\ExportsReport;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Models\Vendor;
use App\Services\Reports\VendorScorecardService;
use App\Support\Modules;
use App\Support\ReportFilters;
use App\Support\TenantScope;
use BackedEnum;
use Carbon\CarbonImmutable;
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
 * How each vendor has actually performed — the screen for {@see VendorScorecardService}.
 *
 * The service, its seven regression tests and its docblock all shipped; the screen did not, so
 * between then and 2026-08-18 the only way to read a scorecard was to call the service from tinker.
 * It sat in docs/ROADMAP.md as a feature to build while the feature was already built.
 *
 * **Counts and times, never a single score** — the service's decision, and this screen keeps it. A
 * composite ranking would have to weight responsiveness against cost against compliance, and that
 * weighting is the operator's judgement at renewal: a vendor who is slow but cheap may be exactly
 * right for routine work. Sorted by SLA breaches because that is the column somebody arriving here
 * is looking for, not because it is "the" ranking.
 *
 * Property-scoped like every other report: the service takes an asset id and gets the selected one.
 */
class VendorScorecard extends Page implements DeliverableReport, HasSchemas, HasTable
{
    use ExportsReport;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use SavesReportViews;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected string $view = 'filament.pages.ledger-report';

    protected static string $routePath = 'vendor-scorecard';

    /** The reporting window (Y-m-d). Defaults to the last 12 months. */
    public string $from;

    public string $to;

    /**
     * Gates on `vendors.view` — this is the vendor register, summarised.
     *
     * Deliberately NOT `reports.view`: the `vendor` role (an external contractor) holds facility
     * rights and no vendor rights at all, and must never read a competitor's response times,
     * penalties or lapsed documents. `vendors.view` is the right that already draws that line.
     */
    public static function canAccess(): bool
    {
        return Modules::enabled('vendors')
            && (Auth::user()?->can('vendors.view') ?? false);
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.reports.vendor_scorecard_nav');
    }

    public function getTitle(): string
    {
        return __('admin.reports.vendor_scorecard_title');
    }

    public function mount(): void
    {
        $to = CarbonImmutable::now();
        $this->to = $to->toDateString();
        $this->from = $to->subMonths(12)->startOfMonth()->toDateString();
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(['sm' => 2])
                ->schema([
                    ReportFilters::from(fn () => null),
                    ReportFilters::to(fn () => null),
                ]),
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function rows(): Collection
    {
        return app(VendorScorecardService::class)->for(
            CarbonImmutable::parse($this->from),
            CarbonImmutable::parse($this->to),
            TenantScope::currentAssetId(),
        );
    }

    public function getSubheading(): ?string
    {
        $rows = $this->rows();

        return __('admin.reports.vendor_scorecard_summary', [
            'vendors' => $rows->count(),
            'breaches' => (int) $rows->sum('sla_breaches'),
            'penalties' => 'EGP '.number_format((float) $rows->sum('penalty_total'), 2),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
            $this->saveViewAction(),
            ...$this->exportActions(),
        ];
    }

    /**
     * The report as CSV, callable without a browser — see App\Contracts\DeliverableReport.
     *
     * Hours are written blank rather than 0 when the service returned null, for its reason: a vendor
     * who never acknowledged anything must not read as instant.
     */
    public function reportCsv(): array
    {
        $hours = fn (?float $v): string => $v === null ? '' : number_format($v, 1, '.', '');

        return [
            'filename' => "vendor-scorecard-{$this->from}-to-{$this->to}",
            'headers' => [
                __('admin.resources.vendor.singular'),
                __('admin.reports.vendor_scorecard_columns.work_orders'),
                __('admin.reports.vendor_scorecard_columns.completed'),
                __('admin.reports.vendor_scorecard_columns.open'),
                __('admin.reports.vendor_scorecard_columns.avg_response_hours'),
                __('admin.reports.vendor_scorecard_columns.avg_resolution_hours'),
                __('admin.reports.vendor_scorecard_columns.sla_breaches'),
                __('admin.reports.vendor_scorecard_columns.repeat_visits'),
                __('admin.reports.vendor_scorecard_columns.penalties_applied'),
                __('admin.reports.vendor_scorecard_columns.penalty_total'),
                __('admin.reports.vendor_scorecard_columns.expired_documents'),
                __('admin.reports.vendor_scorecard_columns.dispatchable'),
            ],
            'rows' => $this->rows()->map(fn (array $r): array => [
                $r['vendor'] instanceof Vendor ? (string) $r['vendor']->name : '',
                (string) $r['work_orders'],
                (string) $r['completed'],
                (string) $r['open'],
                $hours($r['avg_response_hours']),
                $hours($r['avg_resolution_hours']),
                (string) $r['sla_breaches'],
                (string) $r['repeat_visits'],
                (string) $r['penalties_applied'],
                number_format((float) $r['penalty_total'], 2, '.', ''),
                (string) $r['expired_documents'],
                $r['dispatchable']
                    ? __('admin.reports.vendor_scorecard_columns.dispatchable_yes')
                    : __('admin.reports.vendor_scorecard_columns.dispatchable_no'),
            ])->all(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            // Keyed by vendor id so Filament has a stable record key across re-renders; the service
            // already returns one row per vendor.
            ->records(fn () => $this->rows()->keyBy(fn (array $r) => $r['vendor']->getKey()))
            ->columns([
                TextColumn::make('vendor')
                    ->label(__('admin.resources.vendor.singular'))
                    ->state(fn (array $record): string => (string) $record['vendor']->name)
                    // The compliance gate the dispatch path already enforces, said here rather than
                    // in a column of its own: it is a fact ABOUT the vendor, and at renewal it
                    // belongs beside the name.
                    ->description(fn (array $record): ?string => $record['dispatchable']
                        ? null
                        : __('admin.reports.vendor_scorecard_columns.not_dispatchable'))
                    ->weight('medium'),
                TextColumn::make('work_orders')
                    ->label(__('admin.reports.vendor_scorecard_columns.work_orders'))
                    ->alignEnd(),
                TextColumn::make('completed')
                    ->label(__('admin.reports.vendor_scorecard_columns.completed'))
                    ->alignEnd()
                    ->color('success'),
                TextColumn::make('open')
                    ->label(__('admin.reports.vendor_scorecard_columns.open'))
                    ->alignEnd()
                    ->color(fn (array $record): ?string => $record['open'] > 0 ? 'warning' : null),
                // Placeholder, not 0: the service returns null when nothing was ever acknowledged,
                // and "never" must not render as "instant".
                TextColumn::make('avg_response_hours')
                    ->label(__('admin.reports.vendor_scorecard_columns.avg_response_hours'))
                    ->alignEnd()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?float $state): ?string => $state === null ? null : number_format($state, 1)),
                TextColumn::make('avg_resolution_hours')
                    ->label(__('admin.reports.vendor_scorecard_columns.avg_resolution_hours'))
                    ->alignEnd()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?float $state): ?string => $state === null ? null : number_format($state, 1)),
                TextColumn::make('sla_breaches')
                    ->label(__('admin.reports.vendor_scorecard_columns.sla_breaches'))
                    ->alignEnd()
                    ->color(fn (array $record): ?string => $record['sla_breaches'] > 0 ? 'danger' : null)
                    ->weight(fn (array $record): ?string => $record['sla_breaches'] > 0 ? 'bold' : null),
                // The provider who keeps coming back. Bold and red for the same reason a breach is:
                // it is a conversation the renewal has to have.
                TextColumn::make('repeat_visits')
                    ->label(__('admin.reports.vendor_scorecard_columns.repeat_visits'))
                    ->alignEnd()
                    ->color(fn (array $record): ?string => $record['repeat_visits'] > 0 ? 'danger' : null)
                    ->weight(fn (array $record): ?string => $record['repeat_visits'] > 0 ? 'bold' : null),
                TextColumn::make('penalties_applied')
                    ->label(__('admin.reports.vendor_scorecard_columns.penalties_applied'))
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('penalty_total')
                    ->label(__('admin.reports.vendor_scorecard_columns.penalty_total'))
                    ->money('EGP')
                    ->alignEnd(),
                TextColumn::make('expired_documents')
                    ->label(__('admin.reports.vendor_scorecard_columns.expired_documents'))
                    ->alignEnd()
                    ->color(fn (array $record): ?string => $record['expired_documents'] > 0 ? 'danger' : null)
                    ->toggleable(),
            ])
            ->paginated(false)
            ->emptyStateIcon('heroicon-o-trophy')
            ->emptyStateHeading(__('admin.reports.vendor_scorecard_empty'));
    }
}
