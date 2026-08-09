<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\Charge;
use App\Models\Lease;
use Carbon\CarbonImmutable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Filters\SelectFilter;
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
 * **Deliberately read-only.** Rent is changed through the "Change Rent" action, which routes to
 * `LeaseRentChangeService` → `ChargeScheduleService` so the current row is closed and the next
 * opened, the marketing levy follows, and the lease's own rent field stays in step. An editable
 * amount column here would be exactly the silent drift the service exists to prevent — the same
 * reason the rent fields on the lease form are disabled.
 */
class ChargeScheduleRelationManager extends RelationManager
{
    protected static string $relationship = 'charges';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.charge_schedule.title');
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
                    ->getTitleFromRecordUsing(fn (Charge $record): string => __("admin.enums.invoice_item_type.{$record->type}")),
            ])
            ->columns([
                TextColumn::make('type')
                    ->label(__('admin.fields.type'))
                    ->badge()
                    ->sortable()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => __("admin.enums.invoice_item_type.{$state}")),
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
                TextColumn::make('vat_rate')
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
                        ->map(fn ($t) => __("admin.enums.invoice_item_type.{$t}"))
                        ->all()),
            ])
            ->paginated([25, 50, 'all'])
            ->emptyStateHeading(__('admin.charge_schedule.empty'))
            ->emptyStateDescription(__('admin.charge_schedule.empty_description'));
    }

    /** The headline: what this lease bills right now, and when that next changes. */
    public function getTableDescription(): ?string
    {
        /** @var Lease $lease */
        $lease = $this->getOwnerRecord();
        $today = CarbonImmutable::now()->startOfDay();

        $next = Charge::query()
            ->where('lease_id', $lease->getKey())
            ->where('is_active', true)
            ->whereNotNull('start_date')
            ->whereDate('start_date', '>', $today->toDateString())
            ->orderBy('start_date')
            ->first();

        $current = __('admin.charge_schedule.current_rent', [
            'amount' => 'EGP '.number_format((float) $lease->base_rent_monthly, 2),
        ]);

        if (! $next) {
            // "No further steps" is only true if there is no escalation CLAUSE either. A lease
            // that has one but no projected ladder (signed before projection existed) must say so
            // — telling the operator no increase is coming when the contract says otherwise is an
            // answer, and a wrong one. Backfill with `atriom:project-lease-schedules`.
            //
            // Only when the step is still in the FUTURE. A next_escalation_date in the past means
            // the sweep is behind, which is a different problem — saying "not yet scheduled"
            // about an overdue escalation would be a second wrong answer.
            if ($lease->escalation_type === 'fixed_percent'
                && (float) $lease->escalation_rate > 0
                && $lease->next_escalation_date
                && CarbonImmutable::instance($lease->next_escalation_date)->greaterThanOrEqualTo($today)) {
                return $current.' · '.__('admin.charge_schedule.unprojected_escalation', [
                    'rate' => rtrim(rtrim((string) $lease->escalation_rate, '0'), '.'),
                    'date' => CarbonImmutable::instance($lease->next_escalation_date)->format('d/m/Y'),
                ]);
            }

            return $current.' · '.__('admin.charge_schedule.no_further_steps');
        }

        return $current.' · '.__('admin.charge_schedule.next_step', [
            'amount' => 'EGP '.number_format((float) $next->amount, 2),
            'date' => CarbonImmutable::instance($next->start_date)->format('d/m/Y'),
        ]);
    }
}
