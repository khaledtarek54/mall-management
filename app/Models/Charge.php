<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[DeletionAllowed(reason: 'configuration: a recurring billing line; issued invoices keep their own copy')]
class Charge extends Model
{
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['lease_id', 'name', 'type', 'amount', 'frequency', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('charge');
    }

    /**
     * Where a schedule row came from.
     *
     * A lease's rent is a date-ranged SCHEDULE, not a single mutable amount: a change closes the
     * current row and opens the next. Once several rows can exist per type, "why does this lease
     * have four rent rows" must be answerable from the data. See
     * docs/benchmarks/yardi/01-yardi-lease-administration.md §3.2.
     */
    public const ORIGIN_SEED = 'seed';            // written when the lease was created

    public const ORIGIN_MANUAL = 'manual';        // an operator changed the rent

    public const ORIGIN_ESCALATION = 'escalation'; // the annual escalation sweep

    public const ORIGIN_RENEWAL = 'renewal';      // carried onto a renewal lease

    public const ORIGIN_LEVY = 'levy';            // derived from base rent (marketing levy)

    protected $fillable = [
        'lease_id',
        'unit_ownership_id',
        'name',
        'type',
        'origin',
        'amount',
        'currency',
        'frequency',
        'vat_applicable',
        'vat_rate',
        'start_date',
        'end_date',
        'is_active',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'vat_applicable' => 'boolean',
        'is_active' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    /**
     * The unit ownership this assessment belongs to — null for a lease charge.
     *
     * @return BelongsTo<UnitOwnership, $this>
     */
    public function unitOwnership(): BelongsTo
    {
        return $this->belongsTo(UnitOwnership::class);
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    protected static function booted(): void
    {
        // Refuse OVERLAPPING schedule rows of the same type, from any writer.
        //
        // `MonthlyBillingService::assertScheduleUnambiguous()` already refuses to BILL a month two
        // rows cover — but that is the last possible moment to find out, and it fails a whole
        // lease's invoice run. This catches it at the keystroke instead, which is what LS-01
        // actually asked for and what an import or a direct `Charge::create()` needs.
        //
        // ChargeScheduleService cannot produce an overlap by construction (it closes one row the
        // day before the next begins), so this guards the paths that do not go through it.
        //
        // Only RECURRING rows: several one-offs genuinely share a month (a CAM true-up, a
        // percentage-rent overage and a utility recharge), and they are not a schedule.
        static::saving(function (self $charge): void {
            $charge->assertBelongsToExactlyOneAgreement();
            $charge->assertTypeIsAKnownChargeCode();
            $charge->assertNoScheduleOverlap();
        });
    }

    /**
     * The app-layer replacement for the DB enum this column carried until 2026-08-11.
     *
     * Freeing the column is what lets the catalogue reach recurring billing; dropping the check
     * with it would let a typo bill for years. A type is valid when the catalogue knows it — or,
     * for a database whose catalogue is not seeded, when {@see InvoiceItemType} does. Same
     * catalogue-then-floor shape as `Vat::rateForType()` and `InvoiceJournalizer::REVENUE_ROLE`,
     * and for the same reason: an unseeded environment must keep working.
     *
     * Deliberately NOT restricted to ACTIVE codes. Deactivating a code stops it being offered on a
     * new line; it must not make the lease's existing rows unsaveable, or switching one off would
     * break every renewal and rent change on a lease that ever billed it.
     *
     * @throws \DomainException when the type is not a charge code anyone has heard of
     */
    /**
     * A charge belongs to exactly one agreement — a lease OR a unit ownership, never both, never
     * neither.
     *
     * Enforced here rather than as a CHECK constraint because SQLite drops CHECKs on any later
     * `->change()` to the table, so the guard would vanish the next time somebody widened a column
     * and nothing would say so. Same reasoning as `ValueSets` standing in for the DB enums.
     *
     * "Neither" is the one that would be silent: a charge attached to nothing bills nobody, forever,
     * and reads as a perfectly ordinary row on the schedule screen.
     *
     * @throws \DomainException
     */
    public function assertBelongsToExactlyOneAgreement(): void
    {
        $hasLease = $this->lease_id !== null;
        $hasOwnership = $this->unit_ownership_id !== null;

        if ($hasLease === $hasOwnership) {
            throw new \DomainException(__('admin.errors.charge_needs_one_agreement'));
        }
    }

    public function assertTypeIsAKnownChargeCode(): void
    {
        $type = (string) $this->type;

        if ($type === '' || ! $this->isDirty('type')) {
            return;
        }

        $known = ChargeCode::knows($type)
            || in_array($type, \App\Enums\InvoiceItemType::values(), true);

        if (! $known) {
            throw new \DomainException(__('admin.errors.charge_type_unknown', ['type' => $type]));
        }
    }

    /** @throws \DomainException when this row's date range overlaps another of the same type */
    public function assertNoScheduleOverlap(): void
    {
        if (blank($this->lease_id) || $this->frequency === 'one_time' || ! $this->is_active) {
            return;
        }

        $start = $this->start_date ? CarbonImmutable::instance($this->start_date) : null;
        $end = $this->end_date ? CarbonImmutable::instance($this->end_date) : null;

        if ($start && $end && $end->lessThan($start)) {
            throw new \DomainException(__('admin.errors.charge_schedule_inverted', [
                'type' => $this->type,
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ]));
        }

        $clash = static::query()
            ->where('lease_id', $this->lease_id)
            ->where('type', $this->type)
            ->where('is_active', true)
            ->where('frequency', '!=', 'one_time')
            ->when($this->exists, fn ($q) => $q->whereKeyNot($this->getKey()))
            ->get()
            ->first(function (self $other) use ($start, $end): bool {
                $otherStart = $other->start_date ? CarbonImmutable::instance($other->start_date) : null;
                $otherEnd = $other->end_date ? CarbonImmutable::instance($other->end_date) : null;

                // Closed ranges: they overlap unless one ends strictly before the other begins.
                // A null bound is unbounded on that side.
                $endsBefore = $end && $otherStart && $end->lessThan($otherStart);
                $startsAfter = $start && $otherEnd && $start->greaterThan($otherEnd);

                return ! $endsBefore && ! $startsAfter;
            });

        if ($clash) {
            throw new \DomainException(__('admin.errors.charge_schedule_overlap', [
                'type' => $this->type,
                'start' => $start?->toDateString() ?? '—',
                'end' => $end?->toDateString() ?? '∞',
                'other_start' => $clash->start_date?->toDateString() ?? '—',
                'other_end' => $clash->end_date?->toDateString() ?? '∞',
            ]));
        }
    }

    /**
     * Active rows whose date range covers the given day — the schedule row in force then.
     *
     * Open-ended on either side counts as covering, which is what makes the pre-schedule rows
     * (`start_date` = commencement, `end_date` = null) behave exactly as they always have.
     *
     * @param  Builder  $query
     */
    public function scopeEffectiveOn($query, \DateTimeInterface $date)
    {
        $d = Carbon::instance($date)->toDateString();

        return $query
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('start_date')->orWhereDate('start_date', '<=', $d))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $d));
    }

    /** True when this row is still open-ended — the last one in its schedule. */
    public function isOpenEnded(): bool
    {
        return $this->end_date === null;
    }

    /**
     * The rate this charge bills on `$on` — the ONE place that answers it.
     *
     * `vat_rate` is an OVERRIDE, and null is the normal state: the rate comes from the dated
     * catalogue, resolved for the DOCUMENT's date, so a rise entered in advance applies by itself
     * on the day and a back-dated invoice keeps the rate that was in force.
     *
     * Until 2026-08-12 the column held a snapshot taken when the row was written, and
     * `MonthlyBillingService` billed that number for the life of the lease — so a rate change
     * reached every one-off charge and never reached rent or service charge, which is most of the
     * money. Proven, not assumed: with a rise to 20% effective 1 September, the resolver answered
     * 20 for a September document while the September invoice billed 14.
     *
     * `vat_applicable = false` still wins. That is a per-charge statement that this particular
     * supply is not taxed, which is a different question from what the rate is.
     */
    public function resolvedVatRate(?CarbonInterface $on = null): float
    {
        if (! $this->vat_applicable) {
            return 0.0;
        }

        return $this->vat_rate !== null
            ? (float) $this->vat_rate
            : Vat::rateForType((string) $this->type, $on);
    }

    /** Does this charge depart from the catalogue — i.e. did somebody choose its rate? */
    public function hasVatRateOverride(): bool
    {
        return $this->vat_applicable && $this->vat_rate !== null;
    }

    public function calculateVat(?CarbonInterface $on = null): float
    {
        return round($this->amount * ($this->resolvedVatRate($on) / 100), 2);
    }

    public function totalWithVat(?CarbonInterface $on = null): float
    {
        return (float) ($this->amount + $this->calculateVat($on));
    }
}
