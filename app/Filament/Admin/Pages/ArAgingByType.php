<?php

namespace App\Filament\Admin\Pages;

use App\Contracts\DeliverableReport;
use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Pages\Concerns\ExportsReport;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Services\Reports\ReportService;
use App\Support\Modules;
use App\Support\ReportFilters;
use BackedEnum;
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
 * AR aging split by WHAT is owed (story RR-03).
 *
 * **The report exists because a single aging total is ambiguous.** "EGP 400k over 90 days" reads as
 * delinquent rent and prompts a collections call; if most of it is a service-charge line the tenant
 * has formally disputed, the call is the wrong action and the number is the wrong alarm. This is the
 * same money as the AR aging summary, re-cut by charge type, so the grand total ties exactly.
 *
 * Built on `InvoiceItemSettlement` (MF-06), which derives every per-line figure from
 * `invoices.paid_amount` — so these rows sum back to the invoice balances by construction.
 */
class ArAgingByType extends Page implements DeliverableReport, HasSchemas, HasTable
{
    use ExportsReport;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use SavesReportViews;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected string $view = 'filament.pages.ledger-report';

    protected static string $routePath = 'ar-aging-by-type';

    /** The day the debt is aged at (`Y-m-d`). */
    public string $asOf;

    private ?array $report = null;

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
                    // The ageing date is part of the answer, not a hidden constant: "31–60 days"
                    // only means something relative to a day.
                    ReportFilters::asOf(fn () => $this->report = null),
                ]),
        ]);
    }

    public function getTitle(): string
    {
        return __('admin.ar_aging_by_type.title');
    }

    public function getSubheading(): ?string
    {
        $report = $this->report();

        return __('admin.reports.bucket_total').': EGP '.number_format($report['total'], 2)
            .($report['disputed'] > 0
                ? ' · '.__('admin.reports.disputed').': EGP '.number_format($report['disputed'], 2)
                : '')
            .' · '.__('admin.reports.aged_as_of').' '
            .ArAging::parseAsOf($this->asOf)->format('d/m/Y');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.ar_aging_by_type.nav_label');
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
    protected function report(): array
    {
        return $this->report ??= app(ReportService::class)
            ->arAgingByChargeType(ArAging::parseAsOf($this->asOf)->endOfDay());
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function rows(): Collection
    {
        return $this->report()['rows'];
    }

    /**
     * An array lookup, not an interpolated translation key.
     *
     * `__('admin.enums.invoice_item_type.'.$type)` cannot be verified by the translation-coverage
     * gate, and a type with no entry would render the raw key at the operator instead of a label.
     */
    private static function typeLabel(string $type): string
    {
        /** @var array<string, string> $labels */
        $labels = __('admin.enums.invoice_item_type');

        return $labels[$type] ?? $type;
    }

    /**
     * The report as CSV, callable without a browser — see App\Contracts\DeliverableReport.
     *
     * The export action and scheduled delivery both go through this, so an emailed copy is
     * byte-for-byte the report an operator would have downloaded.
     */
    public function reportCsv(): array
    {
        $buckets = ArAging::buckets();

        $headers = [
            __('admin.reports.charge_type'), ...array_values($buckets),
            __('admin.reports.disputed'), __('admin.reports.grand_total'),
        ];

        $rows = $this->rows()->map(fn (array $r): array => [
            self::typeLabel($r['type']),
            ...array_map(fn (string $k) => $r['buckets'][$k], array_keys($buckets)),
            $r['disputed'],
            $r['total'],
        ])->all();

        return [
            'filename' => "ar-aging-by-type-{$this->asOf}",
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    public function table(Table $table): Table
    {
        $bucketColumns = collect(ArAging::buckets())
            ->map(fn (string $label, string $key) => TextColumn::make("buckets.{$key}")
                ->label($label)
                ->money('EGP')
                ->alignEnd()
                // Zero in a bucket is information — it says this charge type is NOT the late money.
                ->placeholder('—')
                ->color(fn ($state): string => match (true) {
                    (float) $state <= 0 => 'gray',
                    in_array($key, ['d_61_90', 'd_90_plus'], true) => 'danger',
                    default => 'warning',
                }))
            ->values()
            ->all();

        return $table
            ->records(fn (): Collection => $this->rows())
            ->searchable(false)
            ->paginated(false)
            ->columns([
                TextColumn::make('type')
                    ->label(__('admin.reports.charge_type'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::typeLabel((string) $state))
                    ->color('gray'),
                ...$bucketColumns,
                // Beside the aged figures, not deducted from them: a disputed balance is still
                // claimed, it is simply not chargeable a late fee yet (MF-07).
                TextColumn::make('disputed')
                    ->label(__('admin.reports.disputed'))
                    ->money('EGP')
                    ->alignEnd()
                    ->placeholder('—')
                    ->color(fn ($state): string => (float) $state > 0 ? 'warning' : 'gray')
                    ->tooltip(__('admin.reports.disputed_hint')),
                TextColumn::make('total')
                    ->label(__('admin.reports.grand_total'))
                    ->money('EGP')
                    ->weight('bold')
                    ->alignEnd(),
            ])
            ->emptyStateHeading(__('admin.ar_aging_by_type.empty'))
            ->emptyStateDescription(__('admin.ar_aging_by_type.empty_description'));
    }
}
