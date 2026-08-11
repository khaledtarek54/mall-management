<?php

namespace App\Models;

use App\Models\Concerns\AllocatesDocumentNumber;
use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Lease extends Model implements HasMedia
{
    use AllocatesDocumentNumber, RefusesDeletionWhenReferenced, HasFactory, HasSearchText, InteractsWithMedia, LogsActivity, SoftDeletes;

    /** The signed contract + supporting paperwork. */
    public const DOCUMENTS_COLLECTION = 'documents';

    /** Fit-out grace suppresses the ENTIRE invoice — rent, service charge, CAM, levy. */
    public const FIT_OUT_GROSS = 'gross';

    /**
     * Fit-out grace abates **base rent only**; the tenant still pays the service charge and every
     * other reimbursement, because the landlord is still incurring those costs while the unit is
     * fitted out. This is the industry standard ("net abatement") and the default for new leases.
     */
    public const FIT_OUT_RENT_ONLY = 'rent_only';

    /** Terminal lease states — immutable once reached (CLAUDE.md invariant). */
    public const TERMINAL_STATUSES = ['terminated', 'expired', 'cancelled', 'renewed'];

    /**
     * The lease TYPE axis — how the lease came about, which is separate from its status.
     *
     * Derived from `previous_lease_id` rather than stored; see {@see leaseType()} for why a column
     * would be a second source of truth for a fact the database already holds.
     */
    public const TYPE_NEW = 'new';

    public const TYPE_RENEWAL = 'renewal';

    /**
     * A lease is found by its reference. Tenant and unit are reached through relation
     * search against THEIR blobs — never copied into this one (see the trait docblock).
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->reference,
        ];
    }

    protected static function booted(): void
    {
        // ── Arm the rent-escalation anniversary on create ──────────────────────────────────────
        // The daily leases:apply-escalations sweep keys on next_escalation_date, but NO creation
        // path used to populate it (the wizard omitted it, the form has no field, renewal set it
        // null) — so escalation silently never ran for a single real lease (dead feature; the tests
        // only passed because fixtures hand-injected the column). Converge the derivation HERE so
        // every path (wizard, standard form, renewal, seeder) arms it consistently and can't drift.
        // Derived only when escalation is genuinely configured; 'none'/rate-0 leases stay null and
        // are never considered by the sweep.
        // Allocate a reference when none was supplied — under the lock, held across the INSERT.
        //
        // Deliberately NOT Invoice's "always re-generate" rule, and the difference is the point.
        // Nothing legitimately supplies an invoice NUMBER, so overwriting one is free. A lease
        // reference is different: **importing an operator's existing leases means importing the
        // contract references they already use**, and those must survive the insert. Overwriting
        // unconditionally also silently renamed any lease created with a deliberate reference.
        //
        // A supplied duplicate is therefore refused by the UNIQUE index rather than quietly
        // renumbered, which is the correct answer for someone else's data. Generated references
        // are safe by construction: `generateUniqueReference()` is MAX-based over `withTrashed()`
        // with a collision loop, and the lock stops two concurrent creates racing to the same
        // number. If the lock times out, the loop and the index remain.
        static::creating(function (self $lease) {
            if (filled($lease->reference)) {
                return;
            }

            $assetCode = $lease->unit?->asset?->code ?: 'AW';

            $lease->reference = $lease->allocateDocumentNumber(
                static::referencePrefix($assetCode),
                fn (): string => static::generateUniqueReference($assetCode),
            );
        });

        static::creating(function (self $lease) {
            $configured = match ($lease->escalation_type) {
                'fixed_percent', 'cpi' => (float) $lease->escalation_rate > 0,
                'fixed_amount' => (float) $lease->escalation_amount > 0,
                default => false,
            };

            if ($lease->next_escalation_date === null
                && $configured
                && $lease->commencement_date !== null) {
                $lease->next_escalation_date = Carbon::parse($lease->commencement_date)->addYear()->format('Y-m-d');
            }
        });

        // ── The escalation collar must be a range, not a contradiction ─────────────────────────
        // A floor above the ceiling has no reading: `RentEscalationService::collar()` applies the
        // floor and then the ceiling, so the ceiling silently wins and the "minimum" the operator
        // typed is the one thing that cannot happen. Refused at the model so an import or an API
        // write is covered too — the form's `gte()` only guards the screen.
        static::saving(function (self $lease) {
            if ($lease->escalation_floor_rate !== null
                && $lease->escalation_ceiling_rate !== null
                && (float) $lease->escalation_floor_rate > (float) $lease->escalation_ceiling_rate) {
                throw new \DomainException(__('admin.errors.escalation_collar_inverted'));
            }
        });

        // ── A lease's term cannot run backwards ────────────────────────────────────────────────
        // `expiry_date` has THREE writers — the standard form, `LeaseTerminationService` (which
        // stamps the termination date onto it) and `LeaseRenewalService` — and the rule lived on
        // exactly one of them, as an `->after()` on the form's DatePicker. The terminate action's
        // DatePicker carried no constraint at all, so terminating with a mis-keyed earlier date
        // produced a lease that reads expired while its status is active, that `activeInPeriod()`
        // can never match (so it bills nothing, ever again), and recurring charges stamped
        // `end_date` BEFORE their own `start_date` — the shape `atriom:audit-charge-schedules`
        // exists to catch on import, minted in-app.
        //
        // Guarded on BOTH columns: fixing only the expiry side leaves the identical broken state
        // reachable by moving commencement forward instead.
        //
        // EQUAL IS ALLOWED. A lease terminated on its own commencement date — a deal that collapses
        // at handover — is legitimate and must stay recordable. The form keeps the stricter
        // `->after()` for NEW leases, where a zero-day term is nonsense: this layer carries the
        // invariant every writer must obey, the form adds the product rule for the create path.
        static::saving(function (self $lease) {
            if ($lease->commencement_date === null || $lease->expiry_date === null) {
                return;
            }

            $commencement = Carbon::parse($lease->commencement_date)->startOfDay();
            $expiry = Carbon::parse($lease->expiry_date)->startOfDay();

            if ($expiry->lt($commencement)) {
                throw new \DomainException(__('admin.errors.lease_expiry_before_commencement', [
                    'commencement' => $commencement->toDateString(),
                    'expiry' => $expiry->toDateString(),
                ]));
            }
        });

        // ── A deposit cannot be negative ───────────────────────────────────────────────────────
        // `minValue(0)` on the form and nothing behind it. Low severity, and worth saying so: this
        // is the CONTRACTUAL figure — the money that actually moves comes from `deposit_transactions`
        // — so a negative one cannot mis-pay anyone. What it can do is print a nonsense
        // "contractual deposit" line on the move-out statement the operator hands the tenant
        // (`MoveOutStatementService::for()`). Refused rather than clamped: silently turning -5,000
        // into 0 hides the typo instead of reporting it.
        static::saving(function (self $lease) {
            if ($lease->security_deposit !== null && (float) $lease->security_deposit < 0) {
                throw new \DomainException(__('admin.errors.negative_security_deposit'));
            }
        });

        // ── Rate-priced rent is DERIVED, from every writer ─────────────────────────────────────
        // A lease priced per m² must never carry a monthly figure that disagrees with its own rate
        // and area. Enforced here rather than in the form so an import, a service or a future
        // screen cannot drift — the same reason the NOT-NULL coercions live at this layer.
        //
        // Only on CREATE and when the rate or basis actually changed: re-deriving on every save
        // would silently overwrite an amount a later expansion legitimately set for a period, and
        // the schedule — not this column — is the record of what billed.
        static::saving(function (self $lease) {
            if ($lease->rent_pricing_basis !== self::RENT_RATE) {
                return;
            }

            // On CREATE always — a typed monthly figure cannot outrank the rate the deal was
            // struck at. On UPDATE only when the rate moved and the caller did NOT state a rent in
            // the same save: `LeaseRentChangeService` re-rates and re-prices together, on an
            // effective date this hook knows nothing about, and must not be second-guessed.
            $stated = $lease->exists && $lease->isDirty('base_rent_monthly');

            if (! $lease->exists
                || ($lease->isDirty(['base_rent_rate_per_sqm_year', 'rent_pricing_basis']) && ! $stated)) {
                $derived = $lease->deriveBaseRentFromRate();

                if ($derived !== null) {
                    $lease->base_rent_monthly = $derived;
                }
            }
        });

        // ── Terminal leases are immutable ──────────────────────────────────────────────────────
        // Once a lease is terminated/expired/cancelled/renewed its fields can't change — only
        // soft-delete/restore (deleted_at). The transition INTO a terminal state is allowed (checked
        // against the ORIGINAL status: termination + renewal both move from 'active'). Closes the
        // hole where the standard Edit form could re-open + mutate a terminated lease.
        static::updating(function (self $lease) {
            $original = $lease->getOriginal('status');
            if (in_array($original, self::TERMINAL_STATUSES, true)) {
                // Block commercial/state changes; still permit benign annotations (notes/metadata),
                // timestamps, and soft-delete/restore. This stops the exploit (re-opening a
                // terminated lease and changing its rent/status/dates) without freezing housekeeping.
                $allowed = ['notes', 'metadata', 'updated_at', 'deleted_at'];
                $blocked = collect($lease->getDirty())->keys()->reject(fn ($k) => in_array($k, $allowed, true));
                if ($blocked->isNotEmpty()) {
                    throw new \DomainException("A '{$original}' lease is immutable — reverse or renew it instead.");
                }
            }
        });
    }

    /**
     * Lease documents live on a PRIVATE disk (not web-accessible). A signed contract is
     * the most confidential artifact in the system — it carries both parties' identities
     * and the commercial terms — and must never be reachable via a guessable public URL.
     * They're served only through the authenticated admin panel.
     *
     * **This was a live exposure until 2026-07-16.** The model implemented HasMedia but
     * registered no collection, so `documents` silently inherited medialibrary's default
     * disk — `env('MEDIA_DISK', 'public')`, and neither the env var nor a config override
     * existed. Every uploaded contract landed in the webroot. Never rely on the default:
     * declare the disk explicitly (MediaPrivacyConformanceTest enforces it).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::DOCUMENTS_COLLECTION)->useDisk('local');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['reference', 'status', 'commencement_date', 'expiry_date', 'term_months', 'base_rent_monthly', 'service_charge_monthly', 'tenant_id', 'unit_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('lease');
    }

    protected $fillable = [
        'reference',
        'unit_id',
        'tenant_id',
        'previous_lease_id',
        'status',
        'commencement_date',
        'expiry_date',
        'holdover_rate_pct',
        'holdover_from',
        'expiry_reminder_notified_at',
        'term_months',
        'base_rent_monthly',
        'rent_pricing_basis',
        'base_rent_rate_per_sqm_year',
        'service_charge_monthly',
        'has_marketing_levy',
        'marketing_levy_rate',
        'possession_date',
        'rent_commencement_date',
        'fit_out_scope',
        'billing_frequency',
        'currency',
        'security_deposit',
        'security_deposit_received',
        'escalation_rate',
        'escalation_amount',
        'escalation_floor_rate',
        'escalation_ceiling_rate',
        'escalation_type',
        'next_escalation_date',
        'has_percentage_rent',
        'percentage_rent_threshold',
        'percentage_rent_rate',
        'percentage_rent_calculation_type',
        'percentage_rent_frequency',
        'percentage_rent_deductible_types',
        'billing_day',
        'payment_terms_days',
        'late_fee_percent',
        'late_fee_grace_days',
        'late_fee_minimum',
        'notes',
        'metadata',
    ];

    // Non-nullable boolean columns: default the in-memory model so a
    // service-created lease (which may omit them) never propagates null into
    // the NOT NULL columns (e.g. on renewal before a DB re-read).
    protected $attributes = [
        'has_percentage_rent' => false,
        'has_marketing_levy' => true, // preserve today's behaviour: every lease gets the levy by default
        // NEW leases default to the STANDARD (net) abatement — base rent free, service charge
        // still payable. The COLUMN default is `gross`, so every lease that already existed keeps
        // the grace it was actually billed under; retroactively re-billing a live tenancy is not a
        // migration. See the migration and docs/benchmarks/yardi/07-phase-plan.md §1 Q2.
        'fit_out_scope' => self::FIT_OUT_RENT_ONLY,
        'billing_frequency' => 'monthly', // bill monthly unless set to quarterly/semiannual/annual
        'percentage_rent_frequency' => 'monthly', // fresh monthly breakpoint unless set to annual (cumulative)
        'security_deposit_received' => false,
    ];

    protected $casts = [
        'percentage_rent_deductible_types' => 'array',
        'commencement_date' => 'date',
        'expiry_date' => 'date',
        'holdover_rate_pct' => 'decimal:2',
        'base_rent_rate_per_sqm_year' => 'decimal:2',
        'late_fee_percent' => 'decimal:2',
        'late_fee_grace_days' => 'integer',
        'late_fee_minimum' => 'decimal:2',
        'holdover_from' => 'date',
        'expiry_reminder_notified_at' => 'datetime',
        'next_escalation_date' => 'date',
        'billing_day' => 'date',
        'base_rent_monthly' => 'decimal:2',
        'service_charge_monthly' => 'decimal:2',
        'security_deposit' => 'decimal:2',
        // Cast declared purely so static analysis reads the column as a string. It was created as a
        // DB-level `enum('none','fixed_percent','cpi')` in 2024, and larastan derives the attribute
        // type from that migration while ignoring the `->change()` that converted it to a varchar —
        // so without this, every comparison against `fixed_amount` reads as "always false". A no-op
        // at runtime; the truth about allowed values lives in the model + form validation, per the
        // project's no-DB-enums convention.
        'escalation_type' => 'string',
        'escalation_rate' => 'decimal:2',
        'escalation_amount' => 'decimal:2',
        'escalation_floor_rate' => 'decimal:2',
        'escalation_ceiling_rate' => 'decimal:2',
        'percentage_rent_threshold' => 'decimal:2',
        'percentage_rent_rate' => 'decimal:2',
        'security_deposit_received' => 'boolean',
        'has_percentage_rent' => 'boolean',
        'has_marketing_levy' => 'boolean',
        'marketing_levy_rate' => 'decimal:2',
        'possession_date' => 'date',
        'rent_commencement_date' => 'date',
        'billing_frequency' => 'string',
        'metadata' => 'array',
    ];

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * All units this lease covers (master + additional) via lease_unit.
     * leases.unit_id stays the MASTER unit (see masterUnit()).
     *
     * @return BelongsToMany<Unit, $this>
     */
    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'lease_unit')
            // The premises are date-ranged, like the rent (LE-02). NULL on either side means
            // unbounded, which is what every pre-existing pivot row means.
            ->withPivot('is_master', 'effective_from', 'effective_to')
            ->withTimestamps();
    }

    /**
     * The units this lease held on a given day.
     *
     * @return \Illuminate\Support\Collection<int, Unit>
     */
    public function unitsOn(CarbonImmutable $on): \Illuminate\Support\Collection
    {
        $this->loadMissing('units');

        /** @var \Illuminate\Support\Collection<int, Unit> $units */
        $units = collect($this->units->all());

        return $units->filter(fn (Unit $unit): bool => self::pivotCovers($unit, $on))->values();
    }

    /**
     * Does this unit's `lease_unit` window cover the given day? NULL on either side is unbounded,
     * which is what every pivot row written before LE-02 means.
     *
     * The pivot is read through `getAttribute()` rather than `->pivot` so the access is one static
     * analysis can see; it is a dynamic relation attribute either way.
     */
    private static function pivotCovers(Unit $unit, CarbonImmutable $on): bool
    {
        [$from, $to] = self::pivotWindow($unit);

        return ! ($from && $from->greaterThan($on)) && ! ($to && $to->lessThan($on));
    }

    /** @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null} */
    private static function pivotWindow(Unit $unit): array
    {
        $pivot = $unit->getAttribute('pivot');

        $from = $pivot?->getAttribute('effective_from');
        $to = $pivot?->getAttribute('effective_to');

        return [
            $from ? CarbonImmutable::parse($from) : null,
            $to ? CarbonImmutable::parse($to) : null,
        ];
    }

    /**
     * Constrain a `lease_unit` query to the pivot rows in force TODAY (LE-02).
     *
     * Lives on Lease rather than Unit because `Unit::allLeases()` is a BelongsToMany whose builder
     * resolves against THIS model — a scope on Unit is unreachable from it.
     *
     * The premises are date-ranged now, so a unit handed back in a contraction has a pivot row that
     * has closed. Occupancy and the double-booking guard must read only the rows still in force, or
     * released space stays permanently unlettable and the mall cannot re-let its own unit.
     * `Unit::allLeases()` itself stays UNFILTERED on purpose: DeletionPolicy uses it to mean "was
     * this unit ever leased", and a unit with history must stay undeletable forever.
     *
     * Every pivot row that exists today has NULL on both sides, so this matches all of them.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\BelongsToMany  $query
     */
    public static function constrainToCurrentlyHeld($query)
    {
        $today = now()->toDateString();

        return $query
            ->where(fn ($q) => $q->whereNull('lease_unit.effective_from')->orWhere('lease_unit.effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('lease_unit.effective_to')->orWhere('lease_unit.effective_to', '>=', $today));
    }

    /**
     * Constrain a `lease_unit` query to rows that have not been RELEASED — in force today **or
     * starting in the future**. This is the double-booking question, and it is deliberately a
     * different predicate from {@see constrainToCurrentlyHeld()}.
     *
     * An expansion agreed in September to take effect in November leaves the unit unoccupied until
     * November — so occupancy says vacant, correctly. But the space is **spoken for**, and asking
     * the occupancy question at the booking guard would let a second lease take it in October and
     * collide with the expansion on 1 November. Only a row whose `effective_to` has PASSED — space
     * genuinely handed back — frees a unit.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\BelongsToMany  $query
     */
    public static function constrainToNotYetReleased($query)
    {
        return $query->where(
            fn ($q) => $q->whereNull('lease_unit.effective_to')
                ->orWhere('lease_unit.effective_to', '>=', now()->toDateString())
        );
    }

    /** The master unit — the lease's primary unit (= leases.unit_id). */
    public function masterUnit(): BelongsTo
    {
        return $this->unit();
    }

    /**
     * The lease's TOTAL leased area — every unit on the lease, not just the master.
     *
     * Anything that apportions a cost by floor area (CAM, and any future recovery basis) must use
     * this, never `$lease->unit->area_sqm`. Reading the master alone understates a multi-unit
     * lease by its whole non-master footprint, and because the CAM denominator is built the same
     * way the shares still sum to 100% — so Σ(allocated) = total_actual_expense stays green while
     * the DISTRIBUTION between tenants is wrong. A tie-out assertion cannot see that; assert the
     * SHARE. (Found by the Yardi benchmark, docs/benchmarks/yardi/04-scenarios.md S5.)
     *
     * Falls back to the master unit when the pivot is empty — pre-observer rows, and any lease
     * built in a test without going through LeaseObserver::ensureMasterPivot().
     */
    public function totalAreaSqm(): float
    {
        return $this->totalAreaSqmOn(CarbonImmutable::now());
    }

    /** Rent typed as a flat monthly figure — the default, and every lease written before LS-04. */
    public const RENT_FLAT = 'flat';

    /** Rent negotiated as a rate per m² per year; the monthly figure is DERIVED from the area. */
    public const RENT_RATE = 'rate';

    /**
     * The monthly base rent implied by a per-m² rate (story LS-04).
     *
     * `rate × area ÷ 12`. Null unless the lease is actually priced that way, so a caller cannot
     * accidentally re-price a flat lease from an area that was never part of its deal.
     */
    public function deriveBaseRentFromRate(?CarbonImmutable $on = null): ?float
    {
        if ($this->rent_pricing_basis !== self::RENT_RATE || (float) $this->base_rent_rate_per_sqm_year <= 0) {
            return null;
        }

        $area = $this->totalAreaSqmOn($on ?? CarbonImmutable::now());

        if ($area <= 0) {
            return null;
        }

        return round((float) $this->base_rent_rate_per_sqm_year * $area / 12, 2);
    }

    /**
     * The lease's total leased area as it stood on a given day (LE-02).
     *
     * Two things vary with the date and both are honoured here: WHICH units the lease held (the
     * `lease_unit` pivot), and how big each one MEASURED (`unit_areas`). Only the first used to be
     * dated, so remeasuring a shop silently rewrote every past period this figure feeds — CAM
     * apportionment above all.
     */
    public function totalAreaSqmOn(CarbonImmutable $on): float
    {
        $fromPivot = (float) $this->unitsOn($on)->sum(fn (Unit $unit) => $unit->areaOn($on));

        return $fromPivot > 0 ? $fromPivot : (float) ($this->unit?->areaOn($on) ?? 0);
    }

    /**
     * The lease's **time-weighted** area across a period — the basis a recovery reconciliation
     * must apportion on.
     *
     * A tenant who took an extra 300 m² on 1 November has not occupied it for the year, and should
     * not carry a whole year of that space's CAM. Yardi re-bases the share from the amendment date;
     * over an annual pool that is the same thing as weighting each unit by the days it was held.
     *
     * For every lease whose pivot rows are unbounded — which is all of them until an expansion or
     * contraction is recorded — this returns exactly `totalAreaSqm()`, so nothing that exists today
     * changes basis.
     */
    public function totalAreaSqmForPeriod(CarbonImmutable $start, CarbonImmutable $end): float
    {
        $this->loadMissing('units');

        $days = $start->diffInDays($end) + 1;

        if ($days <= 0) {
            return $this->totalAreaSqmOn($start);
        }

        $weighted = 0.0;

        foreach ($this->units as $unit) {
            /** @var Unit $unit */
            [$from, $to] = self::pivotWindow($unit);

            $heldFrom = $from && $from->greaterThan($start) ? $from : $start;
            $heldTo = $to && $to->lessThan($end) ? $to : $end;

            if ($heldTo->lessThan($heldFrom)) {
                continue;   // not held at any point in this period
            }

            // The area IN FORCE across the held window, not the unit's current measurement.
            // `area_sqm` is the denormalised TODAY figure, so reading it here apportioned a past
            // year on a wall that moved after that year ended — and because numerator and
            // denominator moved together the tie-out stayed green while the distribution between
            // tenants was wrong. m²·days composes the two weightings (how long the unit was HELD,
            // and what it MEASURED) without rounding in between.
            $weighted += $unit->areaSqmDaysBetween($heldFrom, $heldTo) / $days;
        }

        if ($weighted > 0) {
            return round($weighted, 4);
        }

        // Empty pivot (pre-observer rows, and any lease built without ensureMasterPivot). Fall back
        // to the master unit — but dated the SAME way as the loop above, or this method would answer
        // a past period with today's measurement for exactly the legacy rows least likely to have
        // been re-measured deliberately.
        return $this->unit
            ? round($this->unit->areaSqmDaysBetween($start, $end) / $days, 4)
            : 0.0;
    }

    /**
     * Set the full unit set for this lease and designate one master, keeping
     * leases.unit_id (= master) in sync and recomputing occupancy for every
     * affected unit. The master defaults to the first id when not supplied.
     *
     * @param  array<int>  $unitIds
     */
    public function syncUnits(array $unitIds, ?int $masterUnitId = null): void
    {
        $unitIds = array_values(array_unique(array_map('intval', $unitIds)));
        if ($unitIds === []) {
            return;
        }

        $master = in_array((int) $masterUnitId, $unitIds, true) ? (int) $masterUnitId : $unitIds[0];
        $previous = $this->units()->pluck('units.id')->all();

        $pivot = [];
        foreach ($unitIds as $id) {
            $pivot[$id] = ['is_master' => $id === $master];
        }
        $this->units()->sync($pivot);

        if ((int) $this->unit_id !== $master) {
            $this->forceFill(['unit_id' => $master])->saveQuietly();
        }

        Unit::whereIn('id', array_unique([...$previous, ...$unitIds]))
            ->get()
            ->each
            ->recomputeStatus();
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function previousLease(): BelongsTo
    {
        return $this->belongsTo(Lease::class, 'previous_lease_id');
    }

    /**
     * How this lease came about — Yardi's lease TYPE axis, which is separate from its status.
     *
     * **Derived, never stored.** Yardi keeps type as its own column, and the gap analysis originally
     * called for one here (row 42). It is not needed: `previous_lease_id` is already written by
     * `LeaseRenewalService` and read by two relations, so "is this a renewal" is a fact the database
     * already holds. A `lease_type` column would be a second source of truth for it, and the two
     * would disagree the first time a renewal was created by any path that forgot to set it.
     *
     * Only two values, deliberately. Yardi also types a lease as an *expansion*, but that is a shape
     * Atriom does not have: taking extra space here adds units to the SAME lease and records a
     * `LeaseEvent::TYPE_EXPANSION`, rather than originating a second lease. Holdover is likewise a
     * state the lease enters, not the way it began.
     */
    public function leaseType(): string
    {
        return $this->previous_lease_id !== null ? self::TYPE_RENEWAL : self::TYPE_NEW;
    }

    public function isRenewal(): bool
    {
        return $this->leaseType() === self::TYPE_RENEWAL;
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(Lease::class, 'previous_lease_id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    /**
     * The percentage-rent breakpoint ladder, when this lease is billed on a tiered basis.
     *
     * @return HasMany<LeasePercentageRentTier, $this>
     */
    public function percentageRentTiers(): HasMany
    {
        return $this->hasMany(LeasePercentageRentTier::class)->orderBy('from_amount');
    }

    /**
     * Options recorded on this lease — renewal, termination, expansion, first refusal.
     *
     * @return HasMany<LeaseOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(LeaseOption::class);
    }

    /**
     * The lease's commercial history, newest first — every dated, reasoned change (story LE-01).
     *
     * Newest first because that is the question the timeline answers: "what happened to this lease
     * recently". The reconstruct-a-past-date question sorts the other way and is served by
     * {@see eventsAsOf()}.
     *
     * @return HasMany<LeaseEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(LeaseEvent::class)
            ->orderByDesc('effective_date')
            ->orderByDesc('id');
    }

    /**
     * Parking bays, stores and signage let alongside the premises (space model).
     *
     * Deliberately NOT `units()` — a rentable item is not lettable area, and keeping the two
     * relations apart is what stops one being summed into the other. See
     * [docs/benchmarks/yardi/09-yardi-space-and-parking.md](../../docs/benchmarks/yardi/09-yardi-space-and-parking.md).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<RentableItem, $this>
     */
    public function rentableItems(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(RentableItem::class, 'lease_rentable_item')
            ->withPivot(['effective_from', 'effective_to', 'monthly_rate'])
            ->withTimestamps();
    }

    /**
     * The history as it stood on a past date — the auditor's view.
     *
     * @return \Illuminate\Support\Collection<int, LeaseEvent>
     */
    public function eventsAsOf(CarbonImmutable $on): \Illuminate\Support\Collection
    {
        return $this->events
            ->filter(fn (LeaseEvent $e) => $e->effectiveOn()->lte($on))
            ->sortBy([
                fn (LeaseEvent $a, LeaseEvent $b) => $a->effective_date <=> $b->effective_date,
                fn (LeaseEvent $a, LeaseEvent $b) => $a->id <=> $b->id,
            ])
            ->values();
    }

    /** @return HasMany<LeaseCamTerm, $this> */
    public function camTerms(): HasMany
    {
        return $this->hasMany(LeaseCamTerm::class);
    }

    /**
     * The CAM cost-share ceiling in force for a reconciliation year, or null if the lease has no
     * cap term for/before that year. Picks the effective-dated term with the greatest
     * effective_year ≤ the reconciled year, then resolves its ceiling.
     */
    public function resolveCamCeiling(int $reconciledYear): ?float
    {
        return $this->camTermFor($reconciledYear)?->resolveCeiling($reconciledYear);
    }

    /**
     * The cap scope in force for a reconciliation year (story RC-07) — `total` by default, so every
     * term written before this keeps capping exactly what it capped.
     */
    public function camCapScope(int $reconciledYear): string
    {
        $term = $this->camTermFor($reconciledYear);

        // Both columns are NOT NULL with defaults, so a term always answers; only the ABSENCE of a
        // term needs a fallback.
        return $term === null ? LeaseCamTerm::SCOPE_TOTAL : (string) $term->cap_scope;
    }

    /** Does this lease's cap bank unused headroom for later years? */
    public function camCapCarriesForward(int $reconciledYear): bool
    {
        $term = $this->camTermFor($reconciledYear);

        return $term !== null && (bool) $term->cap_carry_forward;
    }

    /**
     * Headroom banked by earlier reconciled years (story RC-07).
     *
     * Read from the ALLOCATIONS rather than recomputed from the terms: a cap renegotiated in year
     * three must not retroactively change what year one banked, and the allocation is the record of
     * what the tenant was actually billed under. Headroom already drawn on is netted off, so it
     * cannot be spent twice.
     *
     * Only years BEFORE the one being reconciled — a year cannot bank into itself.
     */
    public function camCapHeadroomBankedBefore(int $reconciledYear): float
    {
        $rows = CamAllocation::query()
            ->where('lease_id', $this->id)
            ->whereHas('pool', fn ($q) => $q->where('period_year', '<', $reconciledYear))
            ->get();

        return round(max(0.0, (float) $rows->sum('cap_headroom_banked') - (float) $rows->sum('cap_headroom_used')), 2);
    }

    /** The latest CAM term effective on or before a year — one lookup, four callers. */
    private function camTermFor(int $reconciledYear): ?LeaseCamTerm
    {
        return $this->camTerms()
            ->where('effective_year', '<=', $reconciledYear)
            ->orderByDesc('effective_year')
            ->first();
    }

    /**
     * The recovery share this lease's contract NAMES, if it names one (story RC-03).
     *
     * A stated share beats any derived one: no denominator can produce a percentage the parties
     * simply agreed, and Egyptian commercial leases state one often enough that deriving over the
     * top of it was quietly billing a different number from the contract.
     *
     * Resolved the same way the cap is — the latest term effective on or before the year being
     * reconciled — so a share that was renegotiated mid-term applies from the year it was agreed.
     */
    public function statedCamSharePct(int $reconciledYear): ?float
    {
        $term = $this->camTermFor($reconciledYear);

        return $term?->stated_share_pct !== null ? (float) $term->stated_share_pct : null;
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    // NEVER-deletable money records that reference the lease directly and can exist BEFORE any
    // invoice — a security deposit is recorded at signing, a year of post-dated cheques is lodged up
    // front. Listed so DeletionPolicy blocks deleting a lease that carries them (pre-go-live review).
    public function deposits(): HasMany
    {
        return $this->hasMany(DepositTransaction::class);
    }

    public function postDatedCheques(): HasMany
    {
        return $this->hasMany(PostDatedCheque::class);
    }

    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(TenantRequest::class);
    }

    public function salesDeclarations(): HasMany
    {
        return $this->hasMany(TenantSalesDeclaration::class);
    }

    public function camAllocations(): HasMany
    {
        return $this->hasMany(CamAllocation::class);
    }

    // ============ Derived ============

    public function totalMonthlyAmount(): float
    {
        return (float) ($this->base_rent_monthly + $this->service_charge_monthly);
    }

    public function annualValue(): float
    {
        return $this->totalMonthlyAmount() * 12;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Terminal = terminated/expired/cancelled/renewed — the lease is immutable in this state. */
    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isExpiringSoon(int $days = 90): bool
    {
        if (! $this->expiry_date) {
            return false;
        }

        return $this->expiry_date->isBetween(now(), now()->addDays($days));
    }

    /**
     * Holdover = an active lease PAST its end date. It still occupies the unit + projects it as
     * occupied, but the monthly billing engine excludes it (period past expiry) — so a held-over
     * tenant trades rent-free until someone renews or terminates. Surfaced on the ActionRequired
     * dashboard so it can never go silent. (Automatic holdover *billing* is a deferred decision.)
     */
    public function scopeHoldover($query)
    {
        return $query->where('status', 'active')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now()->toDateString());
    }

    /**
     * Holdovers nobody has dealt with yet — the dashboard's question, which is not the same as the
     * table filter's.
     *
     * `holdover()` is a STATE: past expiry, still active, still occupied. That state persists after
     * an operator converts the lease to holdover billing, and the filter should keep showing it.
     * The ActionRequired card asks something narrower — "what still needs a decision" — and a
     * converted holdover has had its decision. Leaving it on the card would train operators to
     * ignore a card that never empties.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    public function scopeHoldoverNeedingAction($query)
    {
        return $this->scopeHoldover($query)->whereNull('holdover_from');
    }

    public function isHoldover(): bool
    {
        return $this->status === 'active'
            && $this->expiry_date !== null
            && $this->expiry_date->startOfDay()->lt(now()->startOfDay());
    }

    public function daysUntilExpiry(): int
    {
        return (int) now()->diffInDays($this->expiry_date, false);
    }

    /**
     * Fit-out / rent-free grace: the first period for which ANY charge bills.
     *
     * Keyed on `rent_commencement_date`, which replaced the old `fit_out_months` count — a real
     * lease says "rent commences 1 April", not "three months of fit-out", and a month count could
     * not express a mid-month start at all. Null rent-commencement means no grace: the lease bills
     * from its commencement month (operator decision 2026-07-19, OPEN-QUESTIONS C1.5).
     *
     * Null when no commencement date.
     */
    public function firstBillableMonth(): ?CarbonImmutable
    {
        if (! $this->commencement_date) {
            return null;
        }

        $commencement = CarbonImmutable::instance($this->commencement_date)->startOfMonth();

        // Under NET abatement only the rent is free — the service charge still bills — so the
        // lease's first billable month is its commencement, not the end of the fit-out window.
        // Deriving it here means `periodInFitOut()` (nothing bills), the quarterly cycle anchor
        // and the ActionRequired "unbilled leases" card all follow automatically, instead of each
        // growing its own copy of the rule.
        if ($this->fit_out_scope === self::FIT_OUT_RENT_ONLY) {
            return $commencement;
        }

        $rentStart = $this->rentCommencesOn();

        // A rent-commencement on or before the commencement month is not a grace period; it bills
        // from the start. Guarded rather than trusted so a mis-keyed earlier date cannot pull the
        // first billable month BACKWARDS and mint invoices for months before the lease existed.
        return $rentStart !== null && $rentStart->greaterThan($commencement)
            ? $rentStart
            : $commencement;
    }

    /**
     * The month rent starts billing, normalized to the first of that month, or null when no grace
     * is recorded. Billing periods are whole months, so a rent-commencement of 15 April means April
     * is the first billed month — the half-month is a proration question, not a period question.
     */
    public function rentCommencesOn(): ?CarbonImmutable
    {
        return $this->rent_commencement_date
            ? CarbonImmutable::instance($this->rent_commencement_date)->startOfMonth()
            : null;
    }

    /**
     * Is this period inside the rent-free fit-out window at all — regardless of what that grace
     * abates?
     *
     * Distinct from {@see periodInFitOut()}, which asks the narrower question "does NOTHING bill".
     * Under net abatement the answer to that is no while this is still yes, and it is this one the
     * per-charge abatement filter needs.
     */
    public function inFitOutWindow(CarbonImmutable $periodEnd): bool
    {
        if (blank($this->commencement_date)) {
            return false;
        }

        $graceEnds = $this->rentCommencesOn();
        $commencement = CarbonImmutable::instance($this->commencement_date)->startOfMonth();

        // No recorded rent-commencement, or one that is not actually later than commencement, is no
        // grace at all — the same reading the old `fit_out_months <= 0` gave.
        if ($graceEnds === null || ! $graceEnds->greaterThan($commencement)) {
            return false;
        }

        return $periodEnd->lessThan($graceEnds);
    }

    /**
     * Charge types abated for this period — free to the tenant, so they produce no invoice line.
     *
     * Empty for every lease outside its fit-out window, and for `gross` leases (whose grace
     * suppresses the whole invoice before this is ever consulted).
     *
     * **Base rent only, deliberately.** "Net abatement" in the market means the tenant keeps paying
     * the operating-cost reimbursements; the service charge, CAM and the marketing levy are all
     * costs the landlord is genuinely incurring while the unit is fitted out.
     *
     * @return array<int, string>
     */
    public function abatedChargeTypesFor(CarbonImmutable $periodEnd): array
    {
        if ($this->fit_out_scope !== self::FIT_OUT_RENT_ONLY || ! $this->inFitOutWindow($periodEnd)) {
            return [];
        }

        return ['base_rent'];
    }

    /**
     * Is this lease eligible to be billed for the given period at all?
     *
     * **One definition, two callers.** The scheduled run filters eligibility in its query
     * (`scopeBillableForPeriod`); the manual "Generate Invoice" action operates on a lease the
     * operator already picked, so it had no query to filter — and therefore applied NONE of these
     * rules. Measured before this existed: the manual path happily created a real AR invoice (which
     * posts to the GL) for a **terminated** lease, a **draft** lease, and a lease **two months past
     * its expiry**, each of which the batch run correctly refused.
     *
     * Keeping the predicate here and the scope below in lockstep is the point: two copies of
     * "which leases bill" is exactly how the two paths drifted apart in the first place.
     */
    public function isBillableForPeriod(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        // Not yet started: a lease commencing after the period ends bills nothing.
        // blank(), not `=== null`: the column is NOT NULL today, so an explicit null comparison
        // reads as always-true to static analysis — while still being the behaviour we want if the
        // column is ever relaxed.
        if (blank($this->commencement_date)
            || CarbonImmutable::instance($this->commencement_date)->greaterThan($periodEnd)) {
            return false;
        }

        // Already over: an open-ended lease (null expiry) never expires. A lease whose expiry falls
        // before the period starts is finished and bills nothing.
        //
        // …UNLESS an operator has converted it to holdover (story LE-04). Then the parties have
        // continued past expiry, the tenant is in the space, and the mall bills for it from the
        // conversion date. Before this, a held-over tenant traded rent-free and the only response
        // was a dashboard card.
        if (filled($this->expiry_date)
            && CarbonImmutable::instance($this->expiry_date)->lessThan($periodStart)
            && ! $this->isBillableHoldoverFor($periodEnd)) {
            return false;
        }

        return true;
    }

    /** Has this lease been converted to holdover, effective on or before the given period? */
    public function isBillableHoldoverFor(CarbonImmutable $periodEnd): bool
    {
        return filled($this->holdover_from)
            && CarbonImmutable::instance($this->holdover_from)->lessThanOrEqualTo($periodEnd);
    }

    /**
     * The late-fee terms that govern this lease (story MF-08).
     *
     * **Lease first, portfolio default second.** Real leases do not agree on the rate, the minimum
     * or the grace period — an anchor negotiates 30 days, a kiosk gets 5 — and until this existed
     * the sweep applied one global number to all of them.
     *
     * The default comes from `BillingSettings`, **not** `config('billing.*')`. That distinction was
     * a live bug: the admin Settings page writes the settings record while `LateFeeService` read the
     * config file (populated from `env`), so every late-fee value an operator saved on that screen
     * was silently ignored. Reading one source here is what makes the screen mean something.
     *
     * @return array{percent: float, grace_days: int, minimum: float}
     */
    public function lateFeeTerms(): array
    {
        $defaults = app(\App\Settings\BillingSettings::class);

        return [
            'percent' => $this->late_fee_percent !== null
                ? (float) $this->late_fee_percent
                : (float) $defaults->late_fee_percent,
            'grace_days' => $this->late_fee_grace_days !== null
                ? (int) $this->late_fee_grace_days
                : (int) $defaults->late_fee_grace_days,
            'minimum' => $this->late_fee_minimum !== null
                ? (float) $this->late_fee_minimum
                : (float) $defaults->late_fee_minimum,
        ];
    }

    /** Converted to holdover and still running — the state the dashboard should stop nagging about. */
    public function isConvertedHoldover(): bool
    {
        return filled($this->holdover_from);
    }

    /**
     * The query form of {@see isBillableForPeriod()} — used by the scheduled run.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    public function scopeBillableForPeriod($query, CarbonImmutable $periodStart, CarbonImmutable $periodEnd)
    {
        return $query
            ->where('status', 'active')
            ->where('commencement_date', '<=', $periodEnd)
            ->where(function ($q) use ($periodStart, $periodEnd) {
                $q->whereNull('expiry_date')
                    ->orWhere('expiry_date', '>=', $periodStart)
                    // The query half of the holdover exemption above. Kept in lockstep by hand
                    // because that is what this pair is: two copies of "which leases bill", which
                    // is exactly how the manual and scheduled paths drifted apart before.
                    ->orWhere(fn ($h) => $h->whereNotNull('holdover_from')
                        ->where('holdover_from', '<=', $periodEnd));
            });
    }

    /**
     * Active percentage-rent leases that owe a sales declaration for the period and have not filed
     * one — past their fit-out grace, so a lease that isn't billable yet isn't chased either.
     *
     * ONE definition, two callers: `sales:scan-missing-declarations` (which chases the tenant) and
     * the month-end close checklist (which counts them as an outstanding task). The same rule
     * lived only inside the command until 2026-08-08; a second copy in the checklist would have
     * been the third place "which leases owe a declaration" was written down, and the first place
     * it silently disagreed. Same reasoning as `isBillableForPeriod()`/`scopeBillableForPeriod()`
     * above.
     *
     * @return \Illuminate\Support\Collection<int, static>
     */
    public static function missingSalesDeclarationsFor(
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        ?int $assetId = null,
    ): \Illuminate\Support\Collection {
        return static::query()
            ->where('status', 'active')
            ->where('has_percentage_rent', true)
            ->whereNotNull('commencement_date')
            ->whereDate('commencement_date', '<=', $periodEnd)
            ->whereDoesntHave('salesDeclarations', fn ($q) => $q->whereDate('period_start', $periodStart))
            ->when($assetId, fn ($q) => $q->whereHas('unit', fn ($u) => $u->where('asset_id', $assetId)))
            ->with('tenant')
            ->get()
            // Fit-out is a model-level rule, not SQL — a lease still inside its grace is not yet
            // billable, so it is not yet chaseable either.
            ->reject(fn (self $lease) => $lease->periodInFitOut($periodEnd))
            ->values();
    }

    /**
     * True when the given billing period falls entirely inside the fit-out grace, so NOTHING bills.
     * fit_out_months = 0 → always false (today's behaviour). Shared by the monthly billing engine
     * and the ActionRequired "unbilled leases" card, so a lease in fit-out is neither billed nor nagged.
     */
    public function periodInFitOut(CarbonImmutable $periodEnd): bool
    {
        $first = $this->firstBillableMonth();

        return $first !== null && $periodEnd->lessThan($first);
    }

    /** Months in one billing cycle: monthly=1, quarterly=3, semiannual=6, annual=12. */
    public function billingCycleMonths(): int
    {
        return match ($this->billing_frequency) {
            'quarterly' => 3,
            'semiannual' => 6,
            'annual' => 12,
            default => 1,
        };
    }

    /**
     * Is the given month the START of a billing cycle for this lease? Cycles are anchored to the
     * lease's first billable month (commencement + fit-out) and run in full N-month steps
     * (operator decision 2026-07-19 — commencement-anchored, no partial cycles). A month before the
     * first billable month (still in fit-out / not started) is never a cycle start. For a monthly
     * lease every billable month is a cycle start, so this is true from the first billable month on.
     */
    public function isBillingCycleStart(CarbonImmutable $period): bool
    {
        $first = $this->firstBillableMonth();
        if ($first === null) {
            return false;
        }

        $month = $period->startOfMonth();
        if ($month->lessThan($first)) {
            return false;
        }

        $monthsSince = ($month->year - $first->year) * 12 + ($month->month - $first->month);

        return $monthsSince % $this->billingCycleMonths() === 0;
    }

    // ============ Generation helpers ============

    /** `LSE-AW-2026-` — the sequence the numbers below run inside. */
    public static function referencePrefix(string $assetCode = 'AW'): string
    {
        return sprintf('LSE-%s-%s-', $assetCode, now()->format('Y'));
    }

    /**
     * The next lease reference in this property-year.
     *
     * **This was `count() + 1`, and that was a deterministic 500.** `leases.reference` is UNIQUE
     * and the model soft-deletes, so `static::count()` — which the soft-delete scope excludes
     * trashed rows from — falls behind the numbers actually issued. Delete one lease of five and
     * the next create computes `…-0005`, which already exists. The insert throws a duplicate-key
     * error, and it throws again on every subsequent attempt, because the count never recovers:
     * **lease creation stays broken for the rest of the calendar year.** Deleting an unused lease
     * is a supported action (`DeletionPolicy` puts Lease in WHEN_UNUSED, and EditLease offers it),
     * so this was reachable by design, not by misuse.
     *
     * Now MAX-of-prefix over `withTrashed()`, which is monotonic and cannot go backwards — the
     * same shape `Invoice::generateNumber()` has used all along, four files away.
     */
    public static function generateReference(string $assetCode = 'AW'): string
    {
        $prefix = static::referencePrefix($assetCode);

        $last = static::withTrashed()
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')
            ->value('reference');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return sprintf('%s%04d', $prefix, $next);
    }

    /**
     * MAX+1 with a collision loop — the belt to the lock's braces.
     *
     * A gap-free MAX is still only correct if nothing has taken the number since we read it. The
     * lock in `AllocatesDocumentNumber` is the primary guard; this loop is what happens when the
     * lock times out and degrades to unlocked allocation.
     */
    protected static function generateUniqueReference(string $assetCode = 'AW'): string
    {
        $candidate = static::generateReference($assetCode);
        $prefix = static::referencePrefix($assetCode);
        $attempts = 0;

        while (static::withTrashed()->where('reference', $candidate)->exists()) {
            if (++$attempts > 100) {
                throw new \RuntimeException('Unable to allocate a unique lease reference after 100 attempts.');
            }

            $candidate = sprintf('%s%04d', $prefix, (int) substr($candidate, strlen($prefix)) + 1);
        }

        return $candidate;
    }
}
