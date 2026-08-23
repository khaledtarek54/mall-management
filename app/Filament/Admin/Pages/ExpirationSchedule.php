<?php

namespace App\Filament\Admin\Pages;

use App\Contracts\DeliverableReport;
use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Pages\Concerns\ExportsReport;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Services\Reports\ReportService;
use App\Support\Modules;
use App\Support\ReportFilters;
use App\Support\TenantScope;
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
 * The lease expiration schedule — when the mall's income rolls off (RR-02).
 *
 * The rent roll says what the mall earns today; this says when that stops. A year with 30% of the
 * income expiring is a year of negotiations that has to start eighteen months earlier, and the only
 * way to see one before this was to sort the lease table by end date and add the rents up by hand.
 *
 * **Holdovers are their own bucket, listed first.** A lease past its term but still trading has not
 * rolled off — its rent is live and its space is occupied — so burying it in a past year would
 * understate both this year's risk and today's income. It is also the row a leasing manager should
 * act on today rather than in eighteen months.
 */
class ExpirationSchedule extends Page implements DeliverableReport, HasSchemas, HasTable
{
    use ExportsReport;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use SavesReportViews;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected string $view = 'filament.pages.ledger-report';

    protected static string $routePath = 'expiration-schedule';

    /** The day the schedule is taken from (`Y-m-d`). */
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
                    ReportFilters::asOf(fn () => $this->rows = null)
                        ->helperText(__('admin.expiration_schedule.as_of_help')),
                ]),
        ]);
    }

    public function getTitle(): string
    {
        return __('admin.expiration_schedule.title');
    }

    public function getSubheading(): ?string
    {
        $rows = $this->rows();
        $holdover = $rows->firstWhere('bucket', 'holdover');

        return __('admin.expiration_schedule.subheading', [
            'leases' => (int) $rows->sum('leases'),
            'area' => number_format((float) $rows->sum('area_sqm'), 0),
            'annual' => 'EGP '.number_format((float) $rows->sum('annual_rent'), 2),
            'holdover' => (int) ($holdover['leases'] ?? 0),
        ]);
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.expiration_schedule.nav_label');
    }

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
            $this->saveViewAction(),
            ...$this->exportActions(),
        ];
    }

    /** @param array<string, mixed> $bucket */
    public function bucketLabel(array $bucket): string
    {
        return match ($bucket['bucket']) {
            'holdover' => __('admin.expiration_schedule.holdover'),
            'open_ended' => __('admin.expiration_schedule.open_ended'),
            default => (string) $bucket['bucket'],
        };
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function rows(): Collection
    {
        return $this->rows ??= app(ReportService::class)
            ->expirationSchedule(ArAging::parseAsOf($this->asOf), TenantScope::currentAssetId());
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

        // The per-LEASE rows, not the summary: a leasing manager exports this to work
        // the list, and a spreadsheet of four totals is not a work list.
        $headers = [
            __('admin.expiration_schedule.bucket'),
            __('admin.tables.invoice.unit'), __('admin.tables.invoice.tenant'),
            __('admin.tables.lease.reference'), __('admin.rent_roll.area'),
            __('admin.fields.expiry_date'), __('admin.expiration_schedule.monthly_rent'),
            __('admin.expiration_schedule.annual_rent'),
        ];

        $rows = $this->rows()
            ->flatMap(fn (array $bucket) => collect($bucket['rows'])->map(fn (array $r): array => [
                $this->bucketLabel($bucket),
                $r['unit'], $r['tenant'], $r['reference'], $r['area_sqm'],
                $r['expiry_date']?->toDateString(), $r['monthly_rent'], $r['annual_rent'],
            ]))
            ->all();

        return [
            'filename' => "expiration-schedule-{$asOf}",
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
                TextColumn::make('bucket')
                    ->label(__('admin.expiration_schedule.bucket'))
                    ->formatStateUsing(fn (string $state, array $record): string => $this->bucketLabel($record))
                    ->badge()
                    ->color(fn (array $record): string => match (true) {
                        $record['bucket'] === 'holdover' => 'danger',
                        $record['bucket'] === 'open_ended' => 'gray',
                        // The next two years are the ones a leasing manager is working now.
                        (int) $record['bucket'] <= now()->addYear()->year => 'warning',
                        default => 'success',
                    })
                    ->weight('bold'),
                TextColumn::make('leases')
                    ->label(__('admin.expiration_schedule.leases'))
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('area_sqm')
                    ->label(__('admin.rent_roll.area'))
                    ->numeric(0)
                    ->alignEnd()
                    ->suffix(' m²')
                    ->description(fn (array $record): string => $record['share_of_area_pct'].'%'),
                TextColumn::make('annual_rent')
                    ->label(__('admin.expiration_schedule.annual_rent'))
                    ->money('EGP')
                    ->weight('bold')
                    ->alignEnd()
                    // The headline: how much of the mall's income is up in this bucket.
                    ->description(fn (array $record): string => __('admin.expiration_schedule.share_of_rent', [
                        'pct' => $record['share_of_rent_pct'],
                    ])),
                TextColumn::make('rows')
                    ->label(__('admin.expiration_schedule.tenants'))
                    ->state(fn (array $record): string => collect($record['rows'])
                        ->pluck('tenant')
                        ->filter()
                        ->take(4)
                        ->join(', ').(count($record['rows']) > 4 ? ' +'.(count($record['rows']) - 4) : ''))
                    ->wrap()
                    ->color('gray'),
            ])
            ->emptyStateHeading(__('admin.expiration_schedule.empty'))
            ->emptyStateDescription(__('admin.expiration_schedule.empty_description'));
    }
}
