<?php

namespace App\Models;

use App\Contracts\BillableAgreement;
use App\Enums\AssessmentBasis;
use App\Enums\ManagementFeeBasis;
use App\Enums\UnitManagementMode;
use App\Enums\UnitOwnershipStatus;
use App\Enums\UnitTenureType;
use App\Models\Concerns\AllocatesDocumentNumber;
use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PropertyOwned;
use App\Support\DocumentNumbering;
use App\Support\ProrationMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
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
 * @see docs/modules/37-unit-owners.md
 */
#[DeletableWhenUnused(blockedBy: ['invoices', 'charges'], instead: 'transfer the ownership — that is the documented end of a holding, and it keeps the assessment history and the arrears at handover')]
// A unit sale belongs to the mall the unit stands in. `asset_id` is carried directly rather
// than reached through `unit`, deliberately: the assessment sweep asks for every live
// ownership in one property, and a join per row is the N+1 the CAM path already had to fix.
#[PropertyOwned]
class UnitOwnership extends Model implements BillableAgreement
{
    use AllocatesDocumentNumber, HasFactory, HasSearchText, LogsActivity, RefusesDeletionWhenReferenced, SoftDeletes;

    /**
     * An ownership is found by its reference and its purchase contract — the two numbers that exist
     * on paper.
     *
     * The unit code and the owner's name are deliberately NOT copied in: the blob is a pure function
     * of this row's own attributes, and quoting a name here would strand every ownership blob the
     * day that owner is renamed. Both are reached through their own blobs, which is what
     * `UnitOwnershipResource::getGloballySearchableAttributes()` does.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->reference,
            $this->purchase_contract_number,
        ];
    }

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
     * Tenancies the OWNER granted over this unit — Yardi's lessee sub-records.
     *
     * Almost always none or one. They exist so the mall can govern a tenant it did not sign:
     * violations, SLA, fit-out, access. They never make the owner's assessment somebody else's
     * liability.
     *
     * @return HasMany<Lease, $this>
     */
    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
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
    /**
     * Parking bays, storage or signage this owner holds alongside his unit.
     *
     * Voyager's model, not an extension of it: a rentable item is assigned to the customer RECORD
     * (`docs/benchmarks/yardi/09-yardi-space-and-parking.md` §2), and in Voyager Condo/Co-Op the
     * unit owner IS that record. The bay bills as an ordinary recurring charge on this agreement's
     * schedule — which for an owner is the monthly صيانة assessment run — exactly as it bills on a
     * lease's schedule for a tenant. Operator's decision, 2026-08-19.
     *
     * @return MorphToMany<RentableItem, $this>
     */
    public function rentableItems(): MorphToMany
    {
        return $this->morphToMany(RentableItem::class, 'holder', 'rentable_item_holdings')
            ->withPivot(['effective_from', 'effective_to', 'monthly_rate'])
            ->withTimestamps();
    }

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

    // ============ BillableAgreement ============
    //
    // The moment an ownership becomes billable. Everything downstream — `IssueInvoiceService`, AR
    // ageing, late fees, credit notes, the GL — already speaks this contract and needs no idea that
    // owners exist. `Lease` answered the same eight questions from lease law; these answer them from
    // a sale.

    /** @see BillableAgreement::billingTenantId() */
    public function billingTenantId(): int
    {
        return (int) $this->tenant_id;
    }

    /**
     * The property, from this row's own column.
     *
     * A `Lease` has to reach through its unit for this. An ownership does not, which is the same
     * reasoning that put `asset_id` on `invoices` — a financial fact carries its own dimensions.
     *
     * @see BillableAgreement::assetId()
     */
    public function assetId(): ?int
    {
        return $this->asset_id;
    }

    /** @see BillableAgreement::billingCurrency() */
    public function billingCurrency(): string
    {
        return $this->currency ?? 'EGP';
    }

    /**
     * How long the owner has to pay an assessment.
     *
     * The column is NOT NULL with a default, so this is simply the column — **no `?? setting`
     * fallback**, because on a NOT NULL column that branch can never fire and the setting would
     * silently apply to nothing. `Lease::paymentTermsDays()` documents that exact trap (the same
     * dead fallback once sat at eight billing call sites); copying its shape reproduced the bug, and
     * `SettingsReachConformanceTest` caught it.
     *
     * The property's convention belongs at ORIGINATION instead — the form pre-fills a new ownership
     * from it, and from then on the sale carries its own number. That is also the correct semantics:
     * changing a property's default must not move the due date on assessments already raised.
     *
     * @see BillableAgreement::paymentTermsDays()
     */
    public function paymentTermsDays(): int
    {
        return (int) $this->payment_terms_days;
    }

    /**
     * How many months one assessment invoice covers.
     *
     * Monthly, always, and deliberately not configurable yet: no operator has asked for quarterly
     * صيانة, and a `billing_frequency` column nothing reads is the inert-configuration bug. It
     * becomes a column the day somebody needs it — the contract already has the seam.
     *
     * @see BillableAgreement::billingCycleMonths()
     */
    /**
     * How a PARTIAL month of this ownership is priced (EG-29).
     *
     * An ownership has no lease clause to state one, so it resolves on the two tiers it does have:
     * the property, then the portfolio. That is not a gap — the صيانة an owner pays is set by the
     * operator's own schedule, not negotiated document by document the way a lease is.
     */
    public function prorationMethod(): string
    {
        return ProrationMethod::resolve(null, $this->assetId());
    }

    public function billingCycleMonths(): int
    {
        return 1;
    }

    /**
     * Does this ownership bill anything for the period?
     *
     * Two conditions, and they are different questions. The STATUS says the keys have changed hands
     * — the operator carries the unit's cost until handover, not until contract signature. The
     * TENURE says the owner still held it during the period: a resale mid-month bills the seller for
     * the part he owned and the buyer for the rest, because each ownership's own window says so.
     *
     * Measured against the period's LAST day rather than its first, so a handover on the 20th still
     * bills that month (prorated by the run) instead of silently starting the month after.
     *
     * @see BillableAgreement::isBillableForPeriod()
     */
    public function isBillableForPeriod(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): bool
    {
        if ($this->status?->isBillable() !== true) {
            return false;
        }

        // Any overlap at all between the tenure and the period — a tenure that ends mid-period still
        // owes for the days it covered.
        if ($this->started_at !== null && $this->started_at->startOfDay()->gt($periodEnd)) {
            return false;
        }

        return ! ($this->ended_at !== null && $this->ended_at->startOfDay()->lt($periodStart));
    }

    /** @see BillableAgreement::invoiceLinkAttributes() */
    public function invoiceLinkAttributes(): array
    {
        return ['unit_ownership_id' => $this->id, 'lease_id' => null];
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
            // LENGTH first: a plain string sort puts `…-9999` above `…-10000`, so once a
            // series passes its zero-padding MAX returns the wrong row (EG-10).
            ->orderByRaw('LENGTH(reference) DESC, reference DESC')
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
