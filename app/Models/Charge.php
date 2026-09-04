<?php

namespace App\Models;

use App\Support\ActivityLogging;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use App\Support\ProrationMethod;
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
#[PropertyOwned(via: 'lease.unit')]
class Charge extends Model
{
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'charge');
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

    /**
     * Ahead of the period it covers, or behind it — EG-30 (M-2).
     *
     * Rent is settled in ADVANCE: the September invoice asks for September, because that is what a
     * lease says. A service charge or a utility recharge is settled in ARREARS, because until the
     * month has run nobody knows what the common area cost or what the meter read — so the
     * September invoice asks for AUGUST's, on a line that says so.
     *
     * The two ride on the SAME invoice. See the migration for why a second invoice per month was
     * rejected: `MonthlyBillingService::alreadyBilledForMonth()` has silently suppressed a lease's
     * base rent five times over a second invoice dated into a billed month, and every one of those
     * was a one-off — a recurring one would fire monthly for every arrears lease.
     */
    public const TIMING_ADVANCE = 'advance';

    public const TIMING_ARREARS = 'arrears';

    /** @var array<int, string> */
    public const BILLING_TIMINGS = [self::TIMING_ADVANCE, self::TIMING_ARREARS];

    protected $fillable = [
        'lease_id',
        'unit_ownership_id',
        'name',
        'type',
        'origin',
        'amount',
        'currency',
        'frequency',
        'billing_timing',
        'prorate',
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
        'prorate' => 'boolean',
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
        // `MonthlyBillingService::scheduleClash()` already refuses to BILL a month two
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
            $charge->assertFrequencyIsBillable();
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

    /**
     * Refuse a frequency the agreement's own billing run cannot invoice.
     *
     * **A row nobody can bill is the silent kind of wrong.** It saves, it renders on the schedule
     * as a live charge, and the run counts it as an ordinary `skipped` — the same counter as a
     * tenure that genuinely owes nothing this month. Traced on HEAD by reading the two ends, not
     * by running them — `AnAssessmentCarriesOnlyAFrequencyItsRunCanBillTest` is what measures it:
     * `ChargeImporter::getColumns()` validates `frequency` against
     * `ValueSets::allowed('charges', 'frequency')` (all four values) while
     * `BillUnitOwnershipsService::appliesToPeriod()` answers only `monthly` and `one_time` — so an
     * imported quarterly or annual صيانة assessment was never invoiced, for the life of the
     * ownership, with nothing on any screen to say so.
     *
     * On the MODEL, not on the importer and not on the form, for the reason `ValueSets::guard()`
     * is one wildcard listener: there are already three doors onto this table (the importer by way
     * of `ChargeScheduleService`, the ownership form's direct `Charge::create()`, and the lease's
     * own schedule tab) and the fourth is covered by existing rather than by being remembered.
     *
     * Only on a DIRTY frequency, exactly as {@see assertTypeIsAKnownChargeCode()} is: a row
     * written before this guard keeps saving, so an operator can still correct the amount or the
     * dates on a legacy quarterly assessment instead of finding the record frozen — the
     * `#[NeverDeletable]` trap this codebase already records.
     *
     * @throws \DomainException
     */
    public function assertFrequencyIsBillable(): void
    {
        $frequency = (string) $this->frequency;

        if ($frequency === '' || ! $this->isDirty('frequency')) {
            return;
        }

        // Exactly one is set — `assertBelongsToExactlyOneAgreement()` has already refused a row
        // naming both or neither. Null here means the agreement row itself is gone, and a guard
        // cannot judge a frequency against an agreement it cannot read.
        $agreement = $this->lease_id !== null ? $this->lease : $this->unitOwnership;

        if ($agreement === null) {
            return;
        }

        $billable = $agreement->billableChargeFrequencies();

        if (! in_array($frequency, $billable, true)) {
            throw new \DomainException(__('admin.errors.charge_frequency_not_billable', [
                'frequency' => __('admin.charge_schedule.frequencies.'.$frequency),
                'offered' => collect($billable)
                    ->map(fn (string $f): string => __('admin.charge_schedule.frequencies.'.$f))
                    ->implode('، '),
            ]));
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

    /**
     * The agreement this row hangs off, as a `[column => id]` pair.
     *
     * A charge belongs to a lease OR a unit ownership ({@see assertBelongsToExactlyOneAgreement}),
     * so every query that means "the other rows on the SAME agreement" has to key on whichever one
     * it is. Named once here because the overlap guard below is not the only caller that will want
     * it, and a second copy is how the two come to disagree about what an agreement is.
     *
     * @return array{0: string, 1: int}|null null only while the row belongs to neither, which
     *                                       `assertBelongsToExactlyOneAgreement()` refuses anyway
     */
    public function agreementKey(): ?array
    {
        if ($this->lease_id !== null) {
            return ['lease_id', (int) $this->lease_id];
        }

        if ($this->unit_ownership_id !== null) {
            return ['unit_ownership_id', (int) $this->unit_ownership_id];
        }

        return null;
    }

    /** @throws \DomainException when this row's date range overlaps another of the same type */
    public function assertNoScheduleOverlap(): void
    {
        // Keyed on the AGREEMENT, not on `lease_id`. Until 2026-08-19 this returned early whenever
        // `lease_id` was blank — so a unit ownership's assessment schedule was exempt from the one
        // guard that stops a charge being billed twice. Nothing could reach that state while
        // module 37 had no schedule screen; adding one (the same change) is what made it reachable,
        // and two overlapping صيانة rows double-bill an owner exactly as they would a tenant.
        $agreement = $this->agreementKey();

        if ($agreement === null || $this->frequency === 'one_time' || ! $this->is_active) {
            return;
        }

        [$agreementColumn, $agreementId] = $agreement;

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
            ->where($agreementColumn, $agreementId)
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
     * Does this row bill BEHIND the period it covers? — the one definition.
     *
     * Null means advance, and null is the state every charge written before EG-30 is in, so nothing
     * moves on deploy. Compared with `=== self::TIMING_ARREARS` rather than truthily, for the reason
     * `charges.vat_applicable` had to be: a loose test turns "not stated" into a decision, and this
     * column's whole point is that not-stated means the behaviour that already existed.
     */
    public function billsInArrears(): bool
    {
        return $this->billing_timing === self::TIMING_ARREARS;
    }

    /**
     * The proration method THIS row bills on — the agreement's, unless the row opts out entirely.
     *
     * EG-29 made the METHOD a lease term (how a part-month is priced). This answers the prior
     * question Yardi's charge row also carries: whether the row prorates at all. A flat signage
     * licence, a fixed parking fee or a fixed management fee is payable in full for any month the
     * lease runs into — hanging a sign from the 15th does not make it half a sign — and before this
     * existed a mid-month move-in cut every one of them by the same fraction it cut the rent.
     *
     * `false` resolves to {@see ProrationMethod::WHOLE_MONTH} rather than short-circuiting the
     * multiplier, deliberately. `MonthlyBillingService::monthsCovered()` is the ONE definition of
     * how much of a period an agreement runs, and the termination credit reads the same rule so a
     * credit cannot disagree with the invoice it credits; a separate "bill it whole" branch would
     * have been a second definition, and the credit would then claw back half a month this charge
     * says is fully earned. A month the lease does not reach at all still bills nothing — whether a
     * part-month is worth a whole one is a different question from whether the lease ran in it.
     *
     * `=== false`, never falsy: null is the normal state and means the operator has ruled on
     * nothing, which is the trap `charges.vat_applicable` fell into (EG-01).
     */
    public function prorationMethodWithin(string $agreementMethod): string
    {
        return $this->prorate === false
            ? ProrationMethod::WHOLE_MONTH
            : $agreementMethod;
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
     * **`vat_applicable` is an override on the same terms, and null is its normal state too**
     * (EG-01, 2026-08-22). It used to be NOT NULL and written at row-creation from
     * `Vat::rateForType($type) > 0` — the same freeze, one question higher up, and a worse one
     * because this test runs FIRST and returns before the catalogue is consulted. A `base_rent` row
     * was born false (rent is in `Vat::EXEMPT_TYPES`) and could never become taxable again: with the
     * charge code pointed at `VAT_14` the resolver answered 14.0 and the charge still billed 0.0,
     * and a rate the operator had deliberately typed was discarded along with it.
     *
     * So the test is now `=== false`, not falsy. Null means *"nobody has said anything about this
     * charge — ask the catalogue"*, which is what every existing row actually meant; an explicit
     * `false` means *"this particular supply is not taxed"*, which is a different question from what
     * the rate is and still wins over both.
     */
    public function resolvedVatRate(?CarbonInterface $on = null): float
    {
        if ($this->vat_applicable === false) {
            return 0.0;
        }

        return $this->vat_rate !== null
            ? (float) $this->vat_rate
            : Vat::rateForType((string) $this->type, $on);
    }

    /**
     * Does this charge depart from the catalogue — i.e. did somebody choose its rate?
     *
     * Keyed on the rate alone. It used to require `vat_applicable` as well, which with a nullable
     * column would report every genuine override as "no override" — the schedule's ⚠ marker would
     * quietly stop appearing on exactly the rows it exists to flag.
     */
    public function hasVatRateOverride(): bool
    {
        return $this->vat_rate !== null;
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
