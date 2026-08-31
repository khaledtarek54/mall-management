<?php

namespace App\Models;

use App\Support\ActivityLogging;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One abstracted legal term of a lease — the part of the contract that is not money.
 *
 * Voyager's lease abstract *(cited,
 * `docs/benchmarks/yardi/01-yardi-lease-administration.md` §7)*, built for the reason the benchmark
 * gives: **co-tenancy and kick-out are contingent money**, and while they live only in an uploaded
 * PDF nothing can act on them and nothing can even answer *"how many of our leases have a
 * co-tenancy trigger tied to the anchor we are about to lose?"*.
 *
 * ## What this deliberately does NOT do
 *
 * The benchmark notes that "a co-tenancy trigger abates rent automatically in a well-run system".
 * **Atriom records and SURFACES the trigger; it does not abate the rent by itself.** That is a
 * decision, not an omission:
 *
 * - An abatement is money coming off a tenant's bill, and the condition is a legal reading of a
 *   clause ("has the anchor ceased trading?") that the occupancy percentage only approximates. A
 *   system that abates on its own reading would be wrong in exactly the cases that matter — a
 *   temporary closure, a replacement anchor mid-fit-out — and each of those errors is a credit the
 *   operator has to claw back from a tenant who has already banked it.
 * - The same shape as every other contingent charge here: a violation is RECORDED and billed by a
 *   deliberate act; a percentage-rent overage is locked and then billed. Recording the trigger and
 *   raising the abatement are two steps because the second one is somebody's decision.
 *
 * `LeaseReliefService` already exists for the abatement itself, so the operator's route once a
 * trigger fires is a screen they already know.
 */
#[DeletionAllowed(reason: 'an abstract of a contract term: a clause keyed against the wrong lease, or abstracted twice, is ordinary cleanup. The contract PDF remains the source of truth and is untouched by removing a summary of it')]
#[PropertyOwned(via: 'lease.unit')]
class LeaseClause extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * The benchmark's own list, in its order. Not extended with invented types: a clause this
     * operator has that Voyager does not name belongs in `other` with its wording, until it is
     * common enough to earn a row of its own.
     */
    public const TYPE_USE = 'use';

    public const TYPE_EXCLUSIVITY = 'exclusivity';

    public const TYPE_RADIUS = 'radius';

    public const TYPE_CO_TENANCY = 'co_tenancy';

    public const TYPE_KICK_OUT = 'kick_out';

    public const TYPE_ASSIGNMENT = 'assignment';

    public const TYPE_INSURANCE = 'insurance';

    public const TYPE_OPERATING_HOURS = 'operating_hours';

    public const TYPE_SIGNAGE = 'signage';

    public const TYPE_PARKING = 'parking';

    public const TYPE_REPAIRS = 'repairs';

    public const TYPE_GUARANTOR = 'guarantor';

    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_USE, self::TYPE_EXCLUSIVITY, self::TYPE_RADIUS, self::TYPE_CO_TENANCY,
        self::TYPE_KICK_OUT, self::TYPE_ASSIGNMENT, self::TYPE_INSURANCE,
        self::TYPE_OPERATING_HOURS, self::TYPE_SIGNAGE, self::TYPE_PARKING,
        self::TYPE_REPAIRS, self::TYPE_GUARANTOR, self::TYPE_OTHER,
    ];

    /**
     * WHICH NUMBER EACH CLAUSE TYPE MAY CARRY.
     *
     * A co-tenancy clause is an occupancy percentage, a kick-out is a sales figure, a radius is
     * kilometres, and a notice period belongs to the three types that confer a RIGHT — the ones
     * describing a standing obligation (insurance, repairs, signage) have nothing to give notice
     * about.
     *
     * **One definition, read by the form and by the model.** The form hid each field behind its own
     * inline `in_array` check, and the model enforced nothing: change a co-tenancy clause to
     * signage and the 70% occupancy floor stayed on the row — invisible on the form that had just
     * hidden the field, uncorrectable through any screen, and printed as `70.00%` in the clause
     * register beside the word *Signage*. Found by driving the edit modal (2026-08-31).
     *
     * @var array<string, list<string>>
     */
    public const NUMBERS_BY_TYPE = [
        'threshold_pct' => [self::TYPE_CO_TENANCY],
        'threshold_amount' => [self::TYPE_KICK_OUT],
        'radius_km' => [self::TYPE_RADIUS],
        'notice_days' => [self::TYPE_CO_TENANCY, self::TYPE_KICK_OUT, self::TYPE_ASSIGNMENT],
    ];

    /** Whether this clause type carries that number at all — the form's visibility rule. */
    public static function carriesNumber(string $column, ?string $type): bool
    {
        return $type !== null && in_array($type, self::NUMBERS_BY_TYPE[$column] ?? [], true);
    }

    /**
     * The two the benchmark calls contingent money — the ones worth a report of their own.
     *
     * Named here rather than compared inline so the lease screen, the filter and any future alert
     * all read one definition of "this clause can cost us".
     */
    public const CONTINGENT_MONEY = [self::TYPE_CO_TENANCY, self::TYPE_KICK_OUT];

    protected $fillable = [
        'lease_id', 'type', 'summary', 'threshold_pct', 'threshold_amount',
        'radius_km', 'notice_days', 'applies_from', 'applies_to', 'source_reference',
    ];

    protected $casts = [
        'threshold_pct' => 'decimal:2',
        'threshold_amount' => 'decimal:2',
        'radius_km' => 'decimal:2',
        'notice_days' => 'integer',
        'applies_from' => 'date',
        'applies_to' => 'date',
    ];

    protected static function booted(): void
    {
        // A number the clause's type cannot carry is dropped, not kept. Keeping it leaves a value
        // no screen can show and no operator can correct — and the register would print it beside
        // a type it means nothing for. Enforced on the MODEL rather than in the form: the form
        // hides the field, which stops it being SUBMITTED and leaves whatever the row already held.
        static::saving(function (self $clause): void {
            foreach (array_keys(self::NUMBERS_BY_TYPE) as $column) {
                if (! self::carriesNumber($column, $clause->type)) {
                    $clause->{$column} = null;
                }
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'lease_clause');
    }

    /** @return BelongsTo<Lease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /**
     * Is this clause in force on a date?
     *
     * Null on either bound is open-ended — the same convention the charge schedule and the premises
     * pivot use, so a reader who knows one knows all three. A co-tenancy protection that runs for
     * the first three years only is the common case this exists for.
     */
    public function isInForceOn(?CarbonImmutable $on = null): bool
    {
        $on = $on ?? CarbonImmutable::now();

        if ($this->applies_from !== null && $on->lt(CarbonImmutable::parse($this->applies_from))) {
            return false;
        }

        return $this->applies_to === null || $on->lte(CarbonImmutable::parse($this->applies_to));
    }

    /** Clauses in force on a date — the scope the lease screen and any report share. */
    public function scopeInForceOn(Builder $query, ?CarbonImmutable $on = null): Builder
    {
        $date = ($on ?? CarbonImmutable::now())->toDateString();

        return $query
            ->where(fn ($q) => $q->whereNull('applies_from')->orWhereDate('applies_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('applies_to')->orWhereDate('applies_to', '>=', $date));
    }

    /**
     * The clause TYPES that can cost money if their trigger fires — a pure type filter.
     *
     * Rarely what you want on its own; see {@see scopeLiveExposure}, which is the business
     * question. Kept public because "show me every kick-out clause we have ever agreed" is a
     * legitimate different question, and it deliberately includes dead leases.
     */
    public function scopeContingentMoney(Builder $query): Builder
    {
        return $query->whereIn('type', self::CONTINGENT_MONEY);
    }

    /**
     * **The portfolio question, as one call:** which leases are exposed to a contingent-money
     * clause right now?
     *
     * Three conditions, and they are bundled because composing them by hand is how the answer goes
     * wrong. Found by running it (2026-08-19): the first version filtered by clause type and by the
     * clause being in force, and reported a **terminated** lease as exposed — its co-tenancy clause
     * was open-ended, so it read as in force for ever, while the tenancy it protected had ended.
     * An operator asking "who can claim an abatement if the anchor leaves?" would have been handed
     * a tenant who left first.
     *
     *   1. the clause is one of the contingent-money types;
     *   2. the clause is in force on the date;
     *   3. **the lease is still live** — not terminated, expired, cancelled or renewed, and not
     *      soft-deleted.
     *
     * `Lease::TERMINAL_STATUSES` is shared with the lease's own immutability hook, so the two
     * cannot drift about what "ended" means.
     */
    public function scopeLiveExposure(Builder $query, ?CarbonImmutable $on = null): Builder
    {
        return $query
            ->contingentMoney()
            ->inForceOn($on)
            ->whereHas('lease', fn (Builder $lease) => $lease
                ->whereNotIn('status', Lease::TERMINAL_STATUSES));
    }

    /**
     * The clause's own number, whichever of the four columns its type uses — or null when it has
     * none, so a table's `placeholder()` renders rather than a literal dash.
     *
     * **One definition, because there were two and they had already drifted.** The lease's Clauses
     * tab read three columns and omitted `notice_days`, so an assignment clause — whose only number
     * IS the notice period — showed a dash on the very screen where it was entered; the clause
     * register, written later, read all four. Found by driving the tab (2026-08-31).
     *
     * A co-tenancy clause can carry both an occupancy floor and a notice period; the floor is what
     * TRIGGERS it, so that is what this names.
     */
    public function triggerLabel(): ?string
    {
        $trim = fn (float $n): string => rtrim(rtrim(number_format($n, 2), '0'), '.');

        return match (true) {
            $this->threshold_pct !== null => $trim((float) $this->threshold_pct).'%',
            $this->threshold_amount !== null => 'EGP '.number_format((float) $this->threshold_amount, 2),
            $this->radius_km !== null => $trim((float) $this->radius_km).' km',
            $this->notice_days !== null => __('admin.lease_clauses.notice_value', ['days' => $this->notice_days]),
            default => null,
        };
    }

    public function label(): string
    {
        return __('admin.enums.lease_clause_type')[$this->type] ?? $this->type;
    }
}
