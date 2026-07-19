<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'asset_id',
        'area_id',
        'code',
        'floor',
        'category',
        'area_sqm',
        'status',
        'description',
        'features',
    ];

    protected $casts = [
        'features' => 'array',
        'area_sqm' => 'decimal:2',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
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
        return $this->allLeases()
            ->where('leases.status', 'active')
            ->when($excludeLeaseId, fn ($q, $id) => $q->where('leases.id', '!=', $id))
            ->exists();
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

        $statuses = $this->allLeases()->pluck('leases.status');

        $target = match (true) {
            $statuses->contains('active') => 'occupied',
            $statuses->intersect(['draft', 'pending_approval', 'renewed'])->isNotEmpty() => 'reserved',
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
