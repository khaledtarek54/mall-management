<?php

namespace App\Models;

use App\Contracts\BillableAgreement;
use App\Enums\AssessmentBasis;
use App\Enums\ManagementFeeBasis;
use App\Enums\UnitManagementMode;
use App\Enums\UnitOwnershipStatus;
use App\Enums\UnitTenureType;
use App\Models\Concerns\AllocatesDocumentNumber;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\DocumentNumbering;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A unit BUYER's holding — مالك وحدة. The peer of a `Lease`, not a variant of it.
 *
 * A lease is a term with rent; an ownership is a tenure without one. Both give a party rights over a
 * unit and both raise AR, which is what {@see BillableAgreement} names. The owner is a
 * `Tenant` row because that table is the AR PARTY table — Yardi's own answer, and what lets
 * payments, credit notes, deposits, cheques, ageing and the portal serve an owner unchanged.
 *
 * **Not to be confused with `AssetOwner`** — the pivot recording who owns the MALL (Jawad, a `User`,
 * receiving an owner statement). Opposite money directions: a property owner RECEIVES the net, a
 * unit owner PAYS the service charge. `Asset::propertyOwners()` is named for that reason.
 *
 * ## The unknowns are rows, not branches
 *
 * `tenure_type`, `assessment_basis`, `management_mode` and the fee fields are §8's unanswered client
 * questions carried as data — the same way `CamExpensePool` stores its apportionment method rather
 * than branching on it in the service. Every default is today's behaviour, so an unconfigured
 * ownership is inert rather than wrong.
 *
 * @see docs/plans/08-unit-owners.md
 */
class UnitOwnership extends Model
{
    use AllocatesDocumentNumber, HasFactory, LogsActivity, RefusesDeletionWhenReferenced, SoftDeletes;

    protected $fillable = [
        'reference',
        'asset_id',
        'unit_id',
        'tenant_id',
        'tenure_type',
        'status',
        'management_mode',
        'assessment_basis',
        'ownership_share_pct',
        'participation_pct',
        'purchase_contract_number',
        'purchase_date',
        'purchase_price',
        'handover_date',
        'started_at',
        'ended_at',
        'management_fee_pct',
        'fee_basis',
        'payment_terms_days',
        'currency',
        'notes',
    ];

    /**
     * The database defaults, mirrored so a NEW instance already reads the way the stored row will.
     *
     * Without these the column default only lands at INSERT and the in-memory model answers null
     * until it is re-read — so `$ownership->assessment_basis` is null on the object the create
     * returns, and a caller branching on it takes the wrong path on the one code path that matters
     * most (the moments right after a sale is recorded). Same reasoning as `is_listed` on Tenant.
     */
    protected $attributes = [
        'tenure_type' => 'freehold',
        'status' => 'contracted',
        'management_mode' => 'vacant',
        'assessment_basis' => 'area',
        'ownership_share_pct' => 100,
        'payment_terms_days' => 7,
        'currency' => 'EGP',
    ];

    /**
     * Cast to backed PHP enums, never declared as DB enums.
     *
     * **The cast is what refuses.** Assigning an out-of-set value raises a `ValueError` at the
     * moment of assignment, before the row is ever saved — so for these columns the enum, not the
     * `ValueSets` listener, is the guard that fires. Registering them in `ValueSets` anyway is not
     * belt-and-braces theatre: that registry is the shared vocabulary the importers read to build
     * their `Rule::in(...)`, and what the no-DB-enums gate checks the column against. It stays the
     * refusal for every registered column that is NOT cast, which is most of them.
     *
     * The registry declares these sets AS the enum class rather than copying the cases, so the two
     * cannot drift — see `ValueSets::resolved()`.
     *
     * A DB enum would give none of it: widening the set would be an `ALTER TABLE` on a live table,
     * which is exactly what stopped the operator adding a payment rail without a deploy.
     */
    protected $casts = [
        'tenure_type' => UnitTenureType::class,
        'status' => UnitOwnershipStatus::class,
        'management_mode' => UnitManagementMode::class,
        'assessment_basis' => AssessmentBasis::class,
        'fee_basis' => ManagementFeeBasis::class,
        'ownership_share_pct' => 'decimal:2',
        'participation_pct' => 'decimal:4',
        'purchase_price' => 'decimal:2',
        'management_fee_pct' => 'decimal:2',
        'purchase_date' => 'date',
        'handover_date' => 'date',
        'started_at' => 'date',
        'ended_at' => 'date',
    ];

    protected static function booted(): void
    {
        // Same shape as Lease: a supplied reference survives (importing an operator's own contract
        // numbers is the point), and a generated one is allocated under the lock held across the
        // INSERT. See Lease::booted() for why this is not Invoice's always-regenerate rule.
        static::creating(function (self $ownership) {
            if (filled($ownership->reference)) {
                return;
            }

            $assetCode = $ownership->asset?->code ?: 'AW';

            $ownership->reference = $ownership->allocateDocumentNumber(
                static::referencePrefix($assetCode),
                fn (): string => static::generateUniqueReference($assetCode),
            );
        });

        // ── A tenure cannot run backwards ──────────────────────────────────────────────────────
        // Equal IS allowed: a sale that collapses on its own handover date is legitimate and must
        // stay recordable. This is the same rule `Lease` learned the hard way, applied at the model
        // so an import or an API write is covered and not only the form.
        static::saving(function (self $ownership) {
            if ($ownership->started_at !== null
                && $ownership->ended_at !== null
                && Carbon::parse($ownership->started_at)->gt(Carbon::parse($ownership->ended_at))) {
                throw new \DomainException(__('admin.errors.unit_ownership_tenure_inverted'));
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'reference', 'unit_id', 'tenant_id', 'tenure_type', 'status', 'management_mode',
                'assessment_basis', 'ownership_share_pct', 'participation_pct', 'started_at',
                'ended_at', 'management_fee_pct', 'fee_basis',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('unit_ownership');
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * The owner — a `Tenant` row with `party_type = 'unit_owner'`.
     *
     * Named `owner`, not `tenant`, because that is what the operator calls them and what every
     * label says. The underlying table is an implementation fact, not a name for a screen.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Assessments raised against this ownership.
     *
     * Empty until phase 2 writes them; the relation exists now because `DeletionPolicy` blocks a
     * delete on it and `RefusesDeletionWhenReferenced` runs the query for real.
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * The recurring assessment schedule — the ownership's answer to a lease's charges.
     *
     * @return HasMany<Charge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    /**
     * True if this ownership is in effect on $date (default today).
     *
     * Inclusive bounds, either side null = unbounded — identical semantics to `AssetOwner`, stated
     * once in each place rather than shared, because the two tenures are about different things and
     * a shared helper would invite a shared change.
     */
    public function coversDate(\DateTimeInterface|string|null $date = null): bool
    {
        $on = ($date !== null ? Carbon::parse($date) : Carbon::today())->startOfDay();

        if ($this->started_at !== null && $this->started_at->startOfDay()->gt($on)) {
            return false;
        }

        return ! ($this->ended_at !== null && $this->ended_at->startOfDay()->lt($on));
    }

    /**
     * Ownerships whose tenure covers $on — the "who owns this unit today" predicate.
     *
     * A resale sets `ended_at`, so a unit legitimately has several rows and exactly one of them is
     * current. Date-ranging in SQL rather than filtering a loaded collection because the billing
     * sweep asks this of every unit in a property.
     *
     * @param  Builder<UnitOwnership>  $query
     */
    public function scopeCovering(Builder $query, \DateTimeInterface|string|null $on = null): void
    {
        $date = ($on !== null ? Carbon::parse($on) : Carbon::today())->toDateString();

        $query->where(fn (Builder $q) => $q->whereNull('started_at')->orWhereDate('started_at', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('ended_at')->orWhereDate('ended_at', '>=', $date));
    }

    /**
     * Does this ownership bill the service charge for a period covering $on?
     *
     * Both halves matter and they are different questions: `status` says the keys have changed hands
     * (the operator stops carrying the unit's cost at handover, not at contract signature), and the
     * tenure says the owner still held it then. A resold unit answers false for dates after the sale
     * without any row being deleted.
     */
    public function isBillableOn(\DateTimeInterface|string|null $on = null): bool
    {
        return $this->status?->isBillable() === true && $this->coversDate($on);
    }

    public static function referencePrefix(string $assetCode = 'AW'): string
    {
        return sprintf('%s-%s-%s-', DocumentNumbering::prefixFor('unit_ownership'), $assetCode, now()->format('Y'));
    }

    /**
     * The next ownership reference in this property-year.
     *
     * MAX-based over `withTrashed()`, never `count() + 1` — the mistake that made lease creation
     * fail for a whole calendar year once one soft-deleted row fell out of the count while its
     * number stayed taken by the UNIQUE index. See `Lease::generateReference()`.
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

    /** MAX+1 with a collision loop — the belt to the allocation lock's braces. */
    protected static function generateUniqueReference(string $assetCode = 'AW'): string
    {
        $candidate = static::generateReference($assetCode);
        $prefix = static::referencePrefix($assetCode);
        $attempts = 0;

        while (static::withTrashed()->where('reference', $candidate)->exists()) {
            $candidate = sprintf('%s%04d', $prefix, (int) substr($candidate, strlen($prefix)) + 1);

            if (++$attempts > 50) {
                break;
            }
        }

        return $candidate;
    }
}
