<?php

namespace App\Filament\Admin\RelationManagers;

use App\Enums\InvoiceItemType;
use App\Models\Charge;
use App\Models\ChargeCode;
use App\Models\UnitOwnership;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The assessment schedule of a sold unit — what its owner is billed every month (صيانة).
 *
 * ## Why this exists
 *
 * `BillUnitOwnershipsService` bills an ownership from its `charges` rows and skips it when there
 * are none. **No surface in the application created such a row.** `UnitOwnershipResource` had no
 * relation managers, its form has no repeater, `ChargeScheduleRelationManager` is mounted only on
 * `LeaseResource`, and `ChargeImporter` resolves a `lease_reference` only — so the only ownerships
 * in the system with a schedule were the ones `DemoSeeder` wrote directly.
 *
 * The effect in production: an operator registers a sold unit through the panel, the ownership reads
 * `handed_over`, `isBillableForPeriod()` returns true — and the monthly run reports it as an
 * unremarkable `skipped`, every month, forever. This is the third instance of the pattern the
 * project has already named twice: a fully built, fully tested path that nothing can reach. Found
 * by the pre-staging QA run (F-01), not by a failing test — nothing was red, because nothing was
 * wrong; there was simply no way in.
 *
 * ## Why it is not `ChargeScheduleRelationManager`
 *
 * That class is lease law: it types its owner record as a `Lease`, gates on `leases.edit` and the
 * lease's own status, composes `LeaseActions`, and excludes the three types a lease derives from
 * its own services (rent, the marketing levy, parking). An ownership has none of that — it has a
 * tenure and a flat assessment. Generalising it would mean every one of those rules answering "not
 * applicable" at runtime, on the one path where a wrong answer bills the wrong person; the same
 * reasoning that kept `BillUnitOwnershipsService` out of `MonthlyBillingService`.
 *
 * ## Rows are added and ended, never edited
 *
 * The same discipline the lease schedule keeps, for the same reason: an assessment is a dated
 * record, and an amount edited in place silently restates months that have already been billed and
 * paid. To change what an owner pays, end the row and open the next one — the overlap guard on
 * {@see Charge} (which keys on the AGREEMENT, so it covers an ownership) refuses two rows claiming
 * the same month.
 */
class UnitOwnershipChargesRelationManager extends RelationManager
{
    protected static string $relationship = 'charges';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.unit_ownerships.charges.title');
    }

    /** The owner record, typed — `getOwnerRecord()` is declared as a bare Model. */
    private function ownership(): UnitOwnership
    {
        /** @var UnitOwnership $ownership */
        $ownership = $this->getOwnerRecord();

        return $ownership;
    }

    /**
     * One predicate, named once, for the UI and the gate — so `visible()` and `authorize()` cannot
     * drift apart in a later edit.
     *
     * A TRANSFERRED tenure is terminal: its assessments belong to a holding that has ended, and
     * editing them would restate a period the resale certificate already reported on.
     */
    private static function canWriteSchedule(UnitOwnership $ownership): bool
    {
        return (auth()->user()?->can('unit_ownerships.edit') ?? false)
            && $ownership->status?->isTerminal() !== true;
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
            ->recordTitleAttribute('name')
            ->defaultSort('start_date')
            ->emptyStateHeading(__('admin.unit_ownerships.charges.empty_heading'))
            // The empty state carries the consequence, not just the absence: an ownership with no
            // schedule is not "quiet", it is un-billed, and nothing else on the screen says so.
            ->emptyStateDescription(__('admin.unit_ownerships.charges.empty_body'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.fields.name'))
                    ->description(fn (Charge $record): string => ChargeCode::labelFor((string) $record->type))
                    ->searchable(),
                TextColumn::make('amount')
                    ->label(__('admin.fields.amount'))
                    ->money('EGP')
                    ->alignEnd()
                    ->numeric(),
                TextColumn::make('frequency')
                    ->label(__('admin.charge_schedule.frequency'))
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? __("admin.charge_schedule.frequencies.{$state}")
                        : '—'),
                TextColumn::make('start_date')
                    ->label(__('admin.charge_schedule.from'))
                    ->date('d/m/Y'),
                TextColumn::make('end_date')
                    ->label(__('admin.charge_schedule.to'))
                    ->date('d/m/Y')
                    ->placeholder('∞'),
                TextColumn::make('state')
                    ->label(__('admin.fields.status'))
                    ->badge()
                    ->state(fn (Charge $record): string => self::state($record))
                    ->formatStateUsing(fn (string $state): string => __("admin.charge_schedule.states.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'current' => 'success',
                        'future' => 'info',
                        default => 'gray',
                    }),
            ])
            ->headerActions([
                Action::make('addAssessment')
                    ->label(__('admin.unit_ownerships.charges.add'))
                    ->icon('heroicon-o-plus')
                    ->modalHeading(__('admin.unit_ownerships.charges.add'))
                    ->modalDescription(__('admin.unit_ownerships.charges.add_hint'))
                    ->visible(fn (): bool => self::canWriteSchedule($this->ownership()))
                    ->authorize(fn (): bool => self::canWriteSchedule($this->ownership()))
                    ->schema([
                        Select::make('type')
                            // The CATALOGUE, not the enum — an operator's own service-charge code
                            // must be selectable here exactly as it is on a lease.
                            ->label(__('admin.fields.type'))
                            ->options(fn () => ChargeCode::options() ?: InvoiceItemType::options())
                            ->default('service_charge')
                            ->required()
                            ->native(false)
                            ->live(),
                        TextInput::make('name')
                            ->label(__('admin.fields.name'))
                            ->default(__('admin.unit_ownerships.charges.default_name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('amount')
                            ->label(__('admin.fields.amount'))
                            ->prefix('EGP')
                            ->numeric()
                            ->minValue(0.01)
                            ->required()
                            ->helperText(__('admin.unit_ownerships.charges.amount_hint')),
                        Select::make('frequency')
                            ->label(__('admin.charge_schedule.frequency'))
                            // Only the two the assessment run actually understands. It bills a
                            // `monthly` row every month and a `one_time` row once, in the month its
                            // start date falls in; a quarterly row would be silently ignored, which
                            // is worse than not offering it.
                            ->options([
                                'monthly' => __('admin.charge_schedule.frequencies.monthly'),
                                'one_time' => __('admin.charge_schedule.frequencies.one_time'),
                            ])
                            ->default('monthly')
                            ->required()
                            ->native(false),
                        DatePicker::make('start_date')
                            ->label(__('admin.charge_schedule.from'))
                            ->default(fn () => $this->ownership()->handover_date ?? now()->startOfMonth())
                            ->required()
                            ->helperText(__('admin.unit_ownerships.charges.from_hint')),
                        TextInput::make('vat_rate')
                            ->label(__('admin.fields.tax_percent'))
                            ->suffix('%')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            // An OVERRIDE, and blank is the normal state — the catalogue answers at
                            // billing time, for the invoice's own date. Storing the rate here is
                            // what froze it for the life of a lease before 2026-08-12.
                            ->placeholder(fn (Get $get) => $get('type')
                                ? __('admin.charge_schedule.vat_from_catalogue', ['rate' => rtrim(rtrim(number_format(Vat::rateForType($get('type')), 2), '0'), '.')])
                                : null)
                            ->helperText(__('admin.charge_schedule.vat_override_hint')),
                    ])
                    ->action(function (array $data): void {
                        $ownership = $this->ownership();

                        // The real gate. `visible()` is the UI saying so; this refuses a dispatched
                        // payload regardless of what the UI rendered.
                        abort_unless(self::canWriteSchedule($ownership), 403);

                        // Blank => null => the catalogue answers at billing time. An explicit 0 is
                        // the operator saying this charge is not taxed, which is a different claim.
                        $rate = ($data['vat_rate'] ?? '') === '' ? null : (float) $data['vat_rate'];

                        try {
                            Charge::create([
                                'unit_ownership_id' => $ownership->getKey(),
                                'name' => $data['name'],
                                'type' => $data['type'],
                                'origin' => Charge::ORIGIN_MANUAL,
                                'amount' => (float) $data['amount'],
                                'currency' => $ownership->currency ?? 'EGP',
                                'frequency' => $data['frequency'],
                                'vat_applicable' => ($rate ?? Vat::rateForType($data['type'])) > 0,
                                'vat_rate' => $rate,
                                'start_date' => $data['start_date'],
                                'is_active' => true,
                            ]);
                        } catch (\DomainException $e) {
                            // The overlap / unknown-code guards on the model, rendered as a toast
                            // rather than the error page — the operator can fix the dates and retry.
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('admin.unit_ownerships.charges.added'))
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('endAssessment')
                    ->label(__('admin.unit_ownerships.charges.end'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.unit_ownerships.charges.end'))
                    ->modalDescription(__('admin.unit_ownerships.charges.end_hint'))
                    ->visible(fn (Charge $record): bool => self::canWriteSchedule($this->ownership()) && $record->is_active)
                    ->authorize(fn (Charge $record): bool => self::canWriteSchedule($this->ownership()) && $record->is_active)
                    ->schema([
                        DatePicker::make('end_date')
                            ->label(__('admin.charge_schedule.to'))
                            ->default(now()->endOfMonth())
                            ->required(),
                    ])
                    ->action(function (Charge $record, array $data): void {
                        abort_unless(self::canWriteSchedule($this->ownership()) && $record->is_active, 403);

                        // Closed, not deleted. The months it billed are part of the owner's account
                        // and every assessment invoice points at this row.
                        $record->update([
                            'end_date' => $data['end_date'],
                            'is_active' => false,
                        ]);

                        Notification::make()
                            ->title(__('admin.unit_ownerships.charges.ended'))
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
