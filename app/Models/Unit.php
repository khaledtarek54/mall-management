<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends Model
{
    use RefusesDeletionWhenReferenced, HasFactory, HasSearchText, SoftDeletes;

    protected $fillable = [
        'asset_id',
        'area_id',
        'code',
        'floor_id',
        'category',
        'area_sqm',
        'status',
        'description',
    ];

    protected $casts = [
        'area_sqm' => 'decimal:2',
    ];

    /**
     * Unit code is the whole identity of a unit — `A-102`, `G-15`. Floor rides along so
     * "ground A-1" narrows the way an operator says it.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        // The floor CODE, via the register. This blob is a pure function of the row's own
        // attributes everywhere else in the app; `floor_id` is one, and reading the code through it
        // is the one relation hop the search policy allows for a lookup that cannot be renamed
        // without its own rebuild. `atriom:rebuild-search` after renaming a floor.
        return [
            $this->code,
            // `$this->floor()->value('code')`, not `$this->floor?->code`: the latter reads the
            // ATTRIBUTE when one is present and the relation only when it is not, which is exactly
            // the ambiguity that made dropping the old column visible. This always means the
            // relation.
            $this->floor()->value('code'),
        ];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * Which floor this unit stands on — selected from the property's register, not typed.
     *
     *
     * @return BelongsTo<Floor, $this>
     */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    /** The facility zone this unit sits in (module 30) — nullable; a unit may have no zone. */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    /**
     * Every lease that includes this unit (master OR additional) via the
     * lease_unit pivot. This is the occupancy-relevant relationship for
     * multi-unit leases — leases() only finds leases where this is the master.
     */
    public function allLeases(): BelongsToMany
    {
        return $this->belongsToMany(Lease::class, 'lease_unit')->withPivot('is_master');
    }

    /**
     * Is this unit covered by an ACTIVE lease — as master OR as an additional unit? The
     * double-booking guard must consult the lease_unit pivot (the source of truth for which units a
     * lease covers), NOT the denormalized leases.unit_id master pointer: a unit held only as an
     * additional unit in a multi-unit lease has a pivot row but is not any lease's unit_id, so a
     * unit_id-only check would let a second lease re-book it. Pass the lease being validated to
     * exclude itself on edit.
     */
    public function isActivelyLeased(?int $excludeLeaseId = null): bool
    {
        // NOT-YET-RELEASED, not currently-held: a future-dated expansion has already spoken for
        // the unit even though nobody occupies it yet, and letting a second lease take it in the
        // gap is exactly the double-booking this guard exists to stop.
        return Lease::constrainToNotYetReleased(
            $this->allLeases()->where('leases.status', 'active')
        )
            ->when($excludeLeaseId, fn ($q, $id) => $q->where('leases.id', '!=', $id))
            ->exists();
    }

    /**
     * Options that tie this unit up until they are resolved (story OP-03).
     *
     * An expansion right, ROFR, ROFO or purchase option on ANOTHER tenant's lease means this space
     * is spoken for even while it reads as vacant. `LeaseOption::encumbersUnit()` has known this
     * since options shipped and nothing consulted it, so the lease-creation picker offered
     * encumbered units as freely as any other — which is how the same space gets promised twice.
     *
     * Only OPEN options: an exercised, waived or lapsed one encumbers nothing, and treating it as
     * if it did would block space the mall is free to let.
     *
     * @return HasMany<LeaseOption, $this>
     */
    public function encumbrances(): HasMany
    {
        return $this->hasMany(LeaseOption::class)
            ->where('status', 'open')
            ->whereIn('type', LeaseOption::ENCUMBERING_TYPES);
    }

    /** Is this unit spoken for by an option on someone else's lease? */
    public function isEncumbered(?int $exceptLeaseId = null): bool
    {
        return $this->encumbrances
            ->when($exceptLeaseId !== null, fn ($c) => $c->where('lease_id', '!=', $exceptLeaseId))
            ->isNotEmpty();
    }

    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(TenantRequest::class);
    }

    public function utilityMeters(): HasMany
    {
        return $this->hasMany(UtilityMeter::class);
    }

    public function activeLease(): HasOne
    {
        return $this->hasOne(Lease::class)
                    ->where('status', 'active')
                    ->latest('commencement_date');
    }

    public function currentTenant(): ?Tenant
    {
        return $this->activeLease?->tenant;
    }

    /**
     * Project this unit's status from the leases that include it (via the
     * lease_unit pivot, so multi-unit leases count). 'maintenance' is a manual
     * override and never auto-overwritten. Idempotent.
     */
    public function recomputeStatus(): void
    {
        if ($this->status === 'maintenance') {
            return;
        }

        // Two questions, two predicates (LE-02). CURRENTLY HELD decides occupancy: a unit released
        // by a contraction is vacant even though the lease that held it is still active on its
        // remaining units. NOT YET RELEASED decides whether it is spoken for: space an expansion
        // takes in November is not occupied in September, but it is not free either — it is
        // RESERVED, which is what a leasing manager needs to see before marketing it.
        $statuses = Lease::constrainToCurrentlyHeld($this->allLeases())->pluck('leases.status');
        $committed = Lease::constrainToNotYetReleased($this->allLeases())->pluck('leases.status');

        $target = match (true) {
            $statuses->contains('active') => 'occupied',
            $statuses->intersect(['draft', 'pending_approval', 'renewed'])->isNotEmpty() => 'reserved',
            $committed->intersect(['active', 'draft', 'pending_approval', 'renewed'])->isNotEmpty() => 'reserved',
            default => 'vacant',
        };

        if ($this->status !== $target) {
            $this->update(['status' => $target]);
        }
    }

    // Get display name like "Haya Walk · A-01"
    public function fullName(): string
    {
        return "{$this->asset->name} · {$this->code}";
    }
}
