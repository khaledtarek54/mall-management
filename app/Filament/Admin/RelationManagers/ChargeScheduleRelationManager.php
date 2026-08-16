<?php

namespace App\Filament\Admin\RelationManagers;

use App\Enums\InvoiceItemType;
use App\Models\Charge;
use App\Models\ChargeCode;
use App\Models\Lease;
use App\Services\ChargeScheduleService;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The lease's charge schedule — what it has been billed, what it is being billed, and what it will
 * be billed (UX-02, the visual counterpart to the schedule shipped in phase 1).
 *
 * Phase 1 made the rent a date-ranged schedule instead of one mutable amount, and left it visible
 * only in the database: the lease form still showed a single `base_rent_monthly`. This is the
 * screen that makes the model legible — Yardi's Charges grid on the lease record, where each row
 * is a charge code over a date range and the ladder is plain to read.
 *
 * **No row is editable in place.** Rent is changed through the "Change Rent" action, which routes
 * to `LeaseRentChangeService` → `ChargeScheduleService` so the current row is closed and the next
 * opened, the marketing levy follows, and the lease's own rent field stays in step. An editable
 * amount column here would be exactly the silent drift the service exists to prevent — the same
 * reason the rent fields on the lease form are disabled.
 *
 * **Adding and ending a charge live here** (2026-08-11), because nothing else offered them. Rent,
 * service charge, the levy and parking each arrive from their own service, and every other charge
 * code an accountant might add — key money, a chiller charge, a signage fee — had no way onto a
 * lease at all: `charges.type` was a DB enum, so the database refused a code the catalogue knew.
 * Both actions go through `ChargeScheduleService`, so they close-and-open like every other writer
 * rather than editing a row; the amount, dates and VAT of an existing row stay untouchable.
 */
class ChargeScheduleRelationManager extends RelationManager
{
    protected static string $relationship = 'charges';

    /**
     * Charge types no operator may open or end by hand, because something else owns them.
     *
     * Base rent moves through the Change Rent action (which keeps `leases.base_rent_monthly` in
     * step), the marketing levy is DERIVED from base rent by `MarketingLevyService`, and parking is
     * re-derived from the rentable-items pivot on every assign/release. A hand-made row of any of
     * these would be silently overwritten by its owner, or worse, sit beside it and double-bill.
     *
     * @var array<int, string>
     */
    private const DERIVED_TYPES = ['base_rent', 'marketing', 'parking'];

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.charge_schedule.title');
    }

    /** The owner record, typed — `getOwnerRecord()` is declared as a bare Model. */
    private function lease(): Lease
    {
        /** @var Lease $lease */
        $lease = $this->getOwnerRecord();

        return $lease;
    }

    /**
     * One predicate, named once, for the UI and the gate.
     *
     * `visible()` and `authorize()`/`abort_unless()` must agree; naming it here is what stops them
     * drifting apart in a later edit.
     */
    private static function canWriteSchedule(Lease $lease): bool
    {
        return (auth()->user()?->can('leases.edit') ?? false)
            && in_array($lease->status, ['active', 'pending_approval'], true);
    }

    /**
     * A charge type's label — the translation where one exists, the CATALOGUE's own name where it
     * does not.
     *
     * The fallback is the point: a code an accountant added has no `admin.enums.*` key and never
     * will, so without this the schedule would show every one of their codes as the raw
     * `admin.enums.invoice_item_type.chiller_charge` string.
     */
    private static function typeLabel(string $type): string
    {
        // Moved onto the model that owns `label()` once the Billing forecast tab needed the same
        // answer — two copies is how one screen ends up showing `base_rent` in Arabic.
        return ChargeCode::labelFor($type);
    }

    /** In force today · starts later · already ended. The three states an operator scans for. */
    private static function state(Charge $charge): string
    {
        $today = CarbonImmutable::now()->startOfDay();

        if (! $charge->is_active) {
            return 'inactive';
        }

        if ($charge->start_date && CarbonImmutable::instance($charge->start_date)->greaterThan($today)) {
            return 'future';
        }

        if ($charge->end_date && CarbonImmutable::instance($charge->end_date)->lessThan($today)) {
            return 'ended';
        }

        return 'current';
    }

    public function table(Table $table): Table
    {
        return $table
            // CHRONOLOGICAL by default — the schedule reads as one timeline, so "what changes
            // next, across every charge" is answerable at a glance. Grouping by type is one click
            // away (below) for when you want to read a single ladder instead.
            //
            // The hard orderBy that used to live in modifyQueryUsing is gone: it was applied
            // BEFORE the table's own sort, so every column header appended a last-place sort key
            // and clicking one did nothing. Ordering belongs to the table, not to the query.
            // No search box: Charge carries no search_text blob, so the field Filament renders by
            // default could never match anything — a box that always returns nothing teaches an
            // operator the data is missing. A lease's schedule is a handful of rows anyway; the
            // type filter is the useful narrowing. (SearchPolicyConformanceTest enforces this.)
            ->searchable(false)
            ->defaultSort('start_date', 'asc')
            ->groups([
                Group::make('type')
                    ->label(__('admin.fields.type'))
                    ->getTitleFromRecordUsing(fn (Charge $record): string => self::typeLabel($record->type)),
            ])
            ->columns([
                TextColumn::make('type')
                    ->label(__('admin.fields.type'))
                    ->badge()
                    ->sortable()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => self::typeLabel($state)),
                TextColumn::make('amount')
                    ->label(__('admin.fields.amount'))
                    ->money('EGP')
                    ->sortable()
                    ->alignEnd()
                    // The row actually billing right now is the one an operator is looking for.
                    ->weight(fn (Charge $record): ?string => self::state($record) === 'current' ? 'bold' : null),
                TextColumn::make('start_date')
                    ->label(__('admin.charge_schedule.from'))
                    ->date('d/m/Y')
                    ->sortable()
                    // A null start_date means "from the beginning of the lease" — billing treats
                    // it as always-covered. Showing "—" read as *unknown*, which is a different
                    // and worse thing to tell an operator.
                    ->placeholder(__('admin.charge_schedule.from_commencement')),
                TextColumn::make('end_date')
                    ->label(__('admin.charge_schedule.to'))
                    ->date('d/m/Y')
                    ->sortable()
                    // An open-ended row is the end of its ladder, and saying so beats a blank cell.
                    ->placeholder(__('admin.charge_schedule.open_ended')),
                TextColumn::make('state')
                    ->label(__('admin.charge_schedule.state'))
                    ->badge()
                    ->state(fn (Charge $record): string => __('admin.charge_schedule.states.'.self::state($record)))
                    ->color(fn (Charge $record): string => match (self::state($record)) {
                        'current' => 'success',
                        'future' => 'info',
                        'inactive' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('origin')
                    ->label(__('admin.charge_schedule.origin'))
                    // Why this row exists: seeded at creation, typed by an operator, generated by
                    // the escalation clause, carried on renewal, derived from the rent.
                    ->formatStateUsing(fn (?string $state): string => __('admin.charge_schedule.origins.'.($state ?: 'seed')))
                    ->color('gray')
                    ->size('xs'),
                TextColumn::make('frequency')
                    ->label(__('admin.charge_schedule.frequency'))
                    // A CHARGE frequency (monthly/quarterly/annually/one_time) is not the same set
                    // as a LEASE billing_frequency (…/semiannual/annual), so it has its own map.
                    ->formatStateUsing(fn (string $state): string => __("admin.charge_schedule.frequencies.{$state}"))
                    ->toggleable(isToggledHiddenByDefault: true),
                // Shows the rate that will be BILLED, not the stored column — which is usually
                // null now. A row that departs from the catalogue is marked, because that is the
                // one an accountant needs to see.
                TextColumn::make('vat_rate')
                    ->state(fn (Charge $record): string => rtrim(rtrim(number_format($record->resolvedVatRate(), 2), '0'), '.')
                        .($record->hasVatRateOverride() ? ' ⚠' : ''))
                    ->label(__('admin.tables.invoice.vat'))
                    ->suffix('%')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.fields.type'))
                    ->options(fn (): array => Charge::query()
                        ->where('lease_id', $this->getOwnerRecord()->getKey())
                        ->distinct()
                        ->pluck('type', 'type')
                        ->map(fn ($t) => self::typeLabel($t))
                        ->all()),
            ])
            ->headerActions([
                Action::make('addCharge')
                    ->label(__('admin.charge_schedule.add'))
                    ->icon('heroicon-o-plus')
                    ->modalHeading(__('admin.charge_schedule.add'))
                    ->modalDescription(__('admin.charge_schedule.add_hint'))
                    ->visible(fn (): bool => self::canWriteSchedule($this->lease()))
                    ->authorize(fn (): bool => self::canWriteSchedule($this->lease()))
                    ->schema([
                        Select::make('type')
                            // The CATALOGUE, not the enum — the whole point of freeing the column.
                            // Falls back to the enum where the table has not been seeded, exactly
                            // as the invoice-line picker does.
                            ->label(__('admin.fields.type'))
                            ->options(fn () => ChargeCode::options() ?: InvoiceItemType::options())
                            ->required()
                            ->native(false)
                            ->live()
                            // Rent, the levy and parking are DERIVED — from the rent change action,
                            // from base rent, from the rentable-items pivot. Offering them here
                            // would let an operator open a second rent row beside the one those
                            // services maintain, and the two would then disagree.
                            ->disableOptionWhen(fn (string $value) => in_array($value, self::DERIVED_TYPES, true))
                            ->helperText(__('admin.charge_schedule.add_type_hint')),
                        TextInput::make('amount')
                            ->label(__('admin.fields.amount'))
                            ->prefix('EGP')
                            ->numeric()
                            ->minValue(0.01)
                            ->required(),
                        Select::make('frequency')
                            ->label(__('admin.charge_schedule.frequency'))
                            ->options(collect(['monthly', 'quarterly', 'annually', 'one_time'])
                                ->mapWithKeys(fn (string $f) => [$f => __("admin.charge_schedule.frequencies.{$f}")])
                                ->all())
                            ->default('monthly')
                            ->required()
                            ->native(false),
                        DatePicker::make('effective_from')
                            ->label(__('admin.charge_schedule.from'))
                            ->helperText(__('admin.charge_schedule.add_effective_hint'))
                            ->default(now()->startOfMonth())
                            ->required(),
                        TextInput::make('vat_rate')
                            ->label(__('admin.fields.tax_percent'))
                            ->suffix('%')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            // An OVERRIDE, and blank is the normal state — the catalogue answers at
                            // billing time, for the invoice's own date. Filling this in used to be
                            // automatic, which froze the rate for the life of the lease: a rise
                            // entered later reached every one-off charge and never reached rent or
                            // service charge. Leave it empty unless a deal genuinely fixed a rate.
                            ->placeholder(fn (Get $get) => $get('type')
                                ? __('admin.charge_schedule.vat_from_catalogue', ['rate' => rtrim(rtrim(number_format(Vat::rateForType($get('type')), 2), '0'), '.')])
                                : null)
                            ->helperText(__('admin.charge_schedule.vat_override_hint')),
                    ])
                    ->action(function (array $data): void {
                        /** @var Lease $lease */
                        $lease = $this->getOwnerRecord();

                        // Both halves of the gate, server-side: the permission, and the rule that a
                        // derived type is not hand-writable. `disableOptionWhen()` above is the UI
                        // saying so; this is what actually refuses a dispatched payload that names
                        // `base_rent` and would otherwise open a rent row beside the one the rent
                        // change action maintains.
                        abort_unless(
                            self::canWriteSchedule($lease)
                                && ! in_array($data['type'], self::DERIVED_TYPES, true),
                            403,
                        );

                        // Blank => null => the catalogue answers. An explicit 0 is the operator
                        // saying this particular charge is not taxed, which is a different claim.
                        $rate = ($data['vat_rate'] ?? '') === '' ? null : (float) $data['vat_rate'];

                        $charge = app(ChargeScheduleService::class)->setAmount(
                            $lease,
                            $data['type'],
                            (float) $data['amount'],
                            CarbonImmutable::parse($data['effective_from']),
                            [
                                'name' => self::typeLabel($data['type']),
                                'frequency' => $data['frequency'],
                                'vat_applicable' => $rate === null || $rate > 0,
                                'vat_rate' => $rate,
                                // A charge added in September is not owed from the lease's
                                // commencement — without this the first row would back-date to it
                                // and the next run would bill every month since.
                                'first_row_from_effective' => true,
                            ],
                            Charge::ORIGIN_MANUAL,
                        );

                        Notification::make()
                            ->title(__('admin.charge_schedule.added', [
                                'type' => self::typeLabel($data['type']),
                                'amount' => 'EGP '.number_format((float) $data['amount'], 2),
                                'date' => CarbonImmutable::parse($charge->start_date ?? $data['effective_from'])->format('d/m/Y'),
                            ]))
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('endCharge')
                    ->label(__('admin.charge_schedule.end'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Charge $record) => __('admin.charge_schedule.end').' · '.self::typeLabel($record->type))
                    ->modalDescription(__('admin.charge_schedule.end_hint'))
                    // Only a row that is actually billing, and never a derived one: ending the rent
                    // or the levy here would leave the lease's own fields claiming it still bills.
                    ->visible(fn (Charge $record): bool => self::canWriteSchedule($this->lease())
                        && in_array(self::state($record), ['current', 'future'], true)
                        && ! in_array($record->type, self::DERIVED_TYPES, true))
                    ->authorize(fn (): bool => self::canWriteSchedule($this->lease()))
                    ->schema([
                        DatePicker::make('from')
                            ->label(__('admin.charge_schedule.end_from'))
                            ->helperText(__('admin.charge_schedule.end_from_hint'))
                            ->default(now()->startOfMonth())
                            ->required(),
                    ])
                    ->action(function (Charge $record, array $data): void {
                        $lease = $this->lease();

                        abort_unless(self::canWriteSchedule($lease)
                            && ! in_array($record->type, self::DERIVED_TYPES, true), 403);

                        $closed = app(ChargeScheduleService::class)
                            ->close($lease, $record->type, CarbonImmutable::parse($data['from']));

                        Notification::make()
                            ->title(__('admin.charge_schedule.ended', [
                                'type' => self::typeLabel($record->type),
                                'count' => $closed,
                            ]))
                            ->success()
                            ->send();
                    }),
            ])
            ->paginated([25, 50, 'all'])
            ->emptyStateHeading(__('admin.charge_schedule.empty'))
            ->emptyStateDescription(__('admin.charge_schedule.empty_description'));
    }

    /**
     * The headline: what this lease bills right now, and when that next changes.
     *
     * Rewritten 2026-08-16. It read a lease that had not commenced yet as *"Billing now: EGP
     * 100,000 · next step EGP 100,000 on 01/09/2026"* — four wrong readings in one line:
     *
     *  1. **"Billing now" read the lease COLUMN**, not the schedule, so it announced a rent on a
     *     lease that bills nothing for another six weeks. `base_rent_monthly` is the rent in force
     *     *under the contract*; what the schedule bills *today* is a different question, and this
     *     panel is the one place that must answer the second one.
     *  2. **"Next step" was any future-dated row**, so a lease's own opening rent row was reported
     *     as an escalation step — 100,000 "stepping" to 100,000.
     *  3. **The query had no `type` filter**, so the row it called a *rent* step could just as
     *     easily be the service charge or the marketing levy. Nothing ordered the tie beyond
     *     `start_date`, so which one it announced was down to insertion order.
     *  4. **The unprojected-clause warning only knew `fixed_percent`.** A `fixed_amount` lease —
     *     rent rising a contracted EGP 4,000 every year — fell through to "no further steps
     *     scheduled", which is not a hedge but a false statement about the contract.
     *
     * All four are now answered from the rent schedule itself, which is the only thing that decides
     * what actually bills.
     */
    public function getTableDescription(): ?string
    {
        /** @var Lease $lease */
        $lease = $this->getOwnerRecord();
        $today = CarbonImmutable::now()->startOfDay();

        $rentRows = Charge::query()
            ->where('lease_id', $lease->getKey())
            ->where('type', 'base_rent')
            ->where('is_active', true)
            ->whereNotNull('start_date')
            ->orderBy('start_date')
            ->get();

        $inForce = $rentRows->first(fn (Charge $row): bool => ! CarbonImmutable::instance($row->start_date)->greaterThan($today)
            && ($row->end_date === null || ! CarbonImmutable::instance($row->end_date)->lessThan($today)));

        $upcoming = $rentRows->first(fn (Charge $row): bool => CarbonImmutable::instance($row->start_date)->greaterThan($today));

        // What bills today — or, on a lease that has not started, when it starts and at what.
        // Falling back to the lease column covers a lease with no schedule at all (an import that
        // seeded none), where saying "not billing yet" would be a guess about a different problem.
        $current = match (true) {
            $inForce !== null => __('admin.charge_schedule.current_rent', [
                'amount' => 'EGP '.number_format((float) $inForce->amount, 2),
            ]),
            $upcoming !== null => __('admin.charge_schedule.not_billing_yet', [
                'amount' => 'EGP '.number_format((float) $upcoming->amount, 2),
                'date' => CarbonImmutable::instance($upcoming->start_date)->format('d/m/Y'),
            ]),
            default => __('admin.charge_schedule.current_rent', [
                'amount' => 'EGP '.number_format((float) $lease->base_rent_monthly, 2),
            ]),
        };

        // A STEP is a change to a rent already running. Where nothing is in force yet, the upcoming
        // row is the lease's opening rent — already reported above — so the question becomes whether
        // anything is scheduled AFTER it.
        $step = $inForce !== null
            ? $upcoming
            : $rentRows->first(fn (Charge $row): bool => $upcoming !== null
                && CarbonImmutable::instance($row->start_date)->greaterThan(CarbonImmutable::instance($upcoming->start_date)));

        // On a non-monthly lease every row still reads "Monthly", because `charges.frequency` is how
        // often a charge RECURS while `leases.billing_frequency` is how often it is INVOICED — two
        // fields, one word. The panel never said which, so a quarterly lease showed 72,000 beside a
        // schedule that actually raises 216,000. Stated only where it differs from the monthly
        // default: annotating every ordinary lease would be noise.
        $cadence = $lease->billingCycleMonths() > 1
            ? ' · '.__('admin.charge_schedule.billed_cycle', [
                'frequency' => mb_strtolower(__('admin.billing_frequency.'.$lease->billing_frequency)),
                'months' => $lease->billingCycleMonths(),
            ])
            : '';

        if ($step !== null) {
            return $current.' · '.__('admin.charge_schedule.next_step', [
                'amount' => 'EGP '.number_format((float) $step->amount, 2),
                'date' => CarbonImmutable::instance($step->start_date)->format('d/m/Y'),
            ]).$cadence;
        }

        // "No further steps" is only true if there is no escalation CLAUSE either. A lease that has
        // one but no projected ladder (signed before projection existed, or CPI — which is never
        // projected because there is no index feed) must say so: telling the operator no increase is
        // coming when the contract says otherwise is an answer, and a wrong one. Backfill a
        // projectable one with `atriom:project-lease-schedules`.
        //
        // Only when the step is still in the FUTURE. A next_escalation_date in the past means the
        // sweep is behind, which is a different problem — saying "not yet scheduled" about an
        // overdue escalation would be a second wrong answer.
        if ($lease->escalatesContractually()
            && $lease->next_escalation_date
            && CarbonImmutable::instance($lease->next_escalation_date)->greaterThanOrEqualTo($today)) {
            return $current.' · '.__('admin.charge_schedule.unprojected_escalation', [
                'step' => self::escalationStepLabel($lease),
                'date' => CarbonImmutable::instance($lease->next_escalation_date)->format('d/m/Y'),
            ]).$cadence;
        }

        return $current.' · '.__('admin.charge_schedule.no_further_steps').$cadence;
    }

    /** How this lease's contracted step reads, in its own unit — a percentage, pounds, or an index. */
    private static function escalationStepLabel(Lease $lease): string
    {
        return match ((string) $lease->escalation_type) {
            'fixed_amount' => 'EGP '.number_format((float) $lease->escalation_amount, 2),
            'cpi' => __('admin.enums.escalation_type.cpi'),
            default => rtrim(rtrim((string) $lease->escalation_rate, '0'), '.').'%',
        };
    }
}
