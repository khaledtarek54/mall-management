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
 * The rent roll — what the mall is contracted to earn, as at a date (RR-01).
 *
 * The single most-used report in commercial property, and Atriom had no version of it. It could
 * not have had one before phase 1: the rent was a single mutable number, so a rent roll for last
 * March would have reported today's rent and a rent roll for next year could not exist at all.
 * Now every row reads the schedule row **in force on the chosen date**, through the same
 * `ChargeScheduleService::pickInForce()` the billing engine uses.
 *
 * Read-only, property-scoped, CSV-exportable. The as-of date is a first-class filter rather than
 * an implicit "now", because "what were we earning when we signed the loan" and "what will we be
 * earning after the January steps" are the two questions an owner actually asks.
 */
class RentRoll extends Page implements DeliverableReport, HasSchemas, HasTable
{
    use ExportsReport;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use SavesReportViews;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected string $view = 'filament.pages.ledger-report';

    protected static string $routePath = 'rent-roll';

    /** The day the roll is taken (`Y-m-d`). */
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
                        ->helperText(__('admin.rent_roll.as_of_help')),
                ]),
        ]);
    }

    public function getTitle(): string
    {
        return __('admin.rent_roll.title');
    }

    /** The four numbers an owner reads first. */
    public function getSubheading(): ?string
    {
        $rows = $this->rows();
        $area = (float) $rows->sum('area_sqm');
        $rent = (float) $rows->sum('base_rent');

        $line = __('admin.rent_roll.subheading', [
            'leases' => trans_choice('admin.rent_roll.lease_count', $rows->count(), ['count' => $rows->count()]),
            'area' => number_format($area, 2),
            'monthly' => 'EGP '.number_format((float) $rows->sum('total_monthly'), 2),
            // Weighted by area, not an average of the per-unit rates: a 20 m² kiosk must not pull
            // the mall's headline rate around as hard as a 2,000 m² anchor.
            'per_sqm' => $area > 0 ? number_format($rent * 12 / $area, 2) : '—',
            'as_of' => ArAging::parseAsOf($this->asOf)->format('d/m/Y'),
        ]);

        // Say what the date is holding back. A lease signed today and commencing in three weeks is
        // correctly absent from today's roll, and that reads as a broken report to whoever just
        // activated it — they search the unit code, find nothing, and the as-of date in the line
        // above is not something anybody connects to an empty search. The page's `empty` state
        // cannot cover this: the table is full, and only the searched lease is missing.
        //
        // Silent when there is nothing to say, so it stays worth reading (ScopesLedgerReport's
        // unallocated notice, same rule).
        $pending = app(ReportService::class)
            ->rentRollNotYetCommenced(ArAging::parseAsOf($this->asOf), TenantScope::currentAssetId());

        if ($pending > 0) {
            // trans_choice, not a glued-on "(s)": Arabic distinguishes one, two and many.
            $line .= ' · '.trans_choice('admin.rent_roll.not_yet_commenced', $pending, ['count' => $pending]);
        }

        return $line;
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.rent_roll.nav_label');
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
            ->rentRoll(ArAging::parseAsOf($this->asOf), TenantScope::currentAssetId());
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
            __('admin.tables.invoice.unit'), __('admin.tables.invoice.tenant'),
            __('admin.tables.lease.reference'), __('admin.rent_roll.area'),
            __('admin.fields.commencement_date'), __('admin.fields.expiry_date'),
            __('admin.rent_roll.months_remaining'), __('admin.rent_roll.base_rent'),
            __('admin.rent_roll.per_sqm'), __('admin.rent_roll.contracted_rate_header'),
            __('admin.fields.service_charge_monthly'),
            __('admin.rent_roll.marketing'), __('admin.rent_roll.total_monthly'),
            __('admin.fields.escalation_rate'), __('admin.rent_roll.next_step'),
            __('admin.rent_roll.next_option'), __('admin.fields.security_deposit'),
        ];

        $rows = $this->rows()->map(fn (array $r): array => [
            $r['units'], $r['tenant'], $r['reference'], $r['area_sqm'],
            $r['commencement_date']?->toDateString(), $r['expiry_date']?->toDateString(),
            $r['months_remaining'], $r['base_rent'], $r['rent_per_sqm_year'],
            $r['contracted_rate_per_sqm_year'],
            $r['service_charge'], $r['marketing'], $r['total_monthly'],
            $r['escalation_rate'],
            $r['next_step_date'] ? $r['next_step_date']->toDateString().' → '.$r['next_step_amount'] : '',
            $r['next_option_date']?->toDateString(),
            $r['security_deposit'],
        ])->all();

        return [
            'filename' => "rent-roll-{$asOf}",
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
                TextColumn::make('units')
                    ->label(__('admin.tables.invoice.unit'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('tenant')
                    ->label(__('admin.tables.invoice.tenant'))
                    ->weight('medium')
                    ->description(fn (array $record): ?string => $record['reference'])
                    ->placeholder('—'),
                TextColumn::make('area_sqm')
                    ->label(__('admin.rent_roll.area'))
                    ->numeric(2)
                    ->alignEnd()
                    ->suffix(' m²'),
                TextColumn::make('expiry_date')
                    ->label(__('admin.fields.expiry_date'))
                    ->date('d/m/Y')
                    // Months left is the number a leasing manager plans from; the date is context.
                    ->description(fn (array $record): ?string => $record['months_remaining'] !== null
                        ? trans_choice('admin.rent_roll.months_left', $record['months_remaining'], ['count' => $record['months_remaining']])
                        : null)
                    ->placeholder('—'),
                TextColumn::make('base_rent')
                    ->label(__('admin.rent_roll.base_rent'))
                    ->money('EGP')
                    ->weight('bold')
                    ->alignEnd(),
                TextColumn::make('rent_per_sqm_year')
                    ->label(__('admin.rent_roll.per_sqm'))
                    ->numeric(2)
                    ->alignEnd()
                    // The number that lets two deals be compared at all.
                    ->placeholder('—')
                    // On a rate-priced lease (LS-04) the contracted rate sits underneath, so a gap
                    // between signed and effective — an abatement, a step, a hand edit — is visible
                    // rather than something you would have to open the lease to find.
                    ->description(fn (array $record): ?string => $record['contracted_rate_per_sqm_year']
                        && round((float) $record['contracted_rate_per_sqm_year'], 2) !== round((float) $record['rent_per_sqm_year'], 2)
                            ? __('admin.rent_roll.contracted_rate', [
                                'rate' => number_format((float) $record['contracted_rate_per_sqm_year'], 2),
                            ])
                            : null),
                TextColumn::make('total_monthly')
                    ->label(__('admin.rent_roll.total_monthly'))
                    ->money('EGP')
                    ->alignEnd()
                    ->description(fn (array $record): string => __('admin.rent_roll.incl_service', [
                        'service' => number_format($record['service_charge'], 0),
                        'marketing' => number_format($record['marketing'], 0),
                    ])),
                TextColumn::make('next_step_date')
                    ->label(__('admin.rent_roll.next_step'))
                    ->date('d/m/Y')
                    ->description(fn (array $record): ?string => $record['next_step_amount']
                        ? 'EGP '.number_format($record['next_step_amount'], 2)
                        : null)
                    // Phase 1 is what makes this column possible at all: before the schedule, next
                    // year's rent did not exist until the night a job created it.
                    ->placeholder(__('admin.rent_roll.no_step'))
                    ->toggleable(),
                TextColumn::make('next_option_date')
                    ->label(__('admin.rent_roll.next_option'))
                    ->date('d/m/Y')
                    ->badge()
                    ->color('warning')
                    ->description(fn (array $record): ?string => $record['next_option_type']
                        ? __("admin.lease_options.types.{$record['next_option_type']}")
                        : null)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('security_deposit')
                    ->label(__('admin.fields.security_deposit'))
                    ->money('EGP')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordUrl(fn (array $record): ?string => LeaseResource::getUrl('edit', ['record' => $record['lease_id']]))
            ->emptyStateHeading(__('admin.rent_roll.empty'))
            ->emptyStateDescription(__('admin.rent_roll.empty_description'));
    }
}
