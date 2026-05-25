<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Asset extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * Reserved code for the synthetic "All Properties" tenant — the
     * pseudo-asset shown in the property switcher that bypasses
     * per-property scoping. Backed by a real DB row so Filament can
     * resolve it from the URL slug.
     */
    public const ALL_PROPERTIES_CODE = 'ALL';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'type', 'city', 'leasable_area_sqm', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('asset');
    }

    protected $fillable = [
        'name',
        'code',
        'type',
        'address',
        'city',
        'country',
        'total_area_sqm',
        'leasable_area_sqm',
        'currency',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
        'total_area_sqm' => 'decimal:2',
        'leasable_area_sqm' => 'decimal:2',
    ];

    public function isAllProperties(): bool
    {
        return $this->code === self::ALL_PROPERTIES_CODE;
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function camPools(): HasMany
    {
        return $this->hasMany(CamExpensePool::class);
    }

    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'asset_owner')
            ->withPivot(['ownership_percentage', 'started_at', 'ended_at'])
            ->withTimestamps();
    }

    /**
     * Staff (admin panel users) assigned to this property. Distinct from
     * `owners()` which is the legal-ownership relationship.
     */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'asset_user')
            ->withPivot(['role', 'assigned_at', 'ended_at', 'notes'])
            ->withTimestamps();
    }

    public function utilityMeters(): HasMany
    {
        return $this->hasMany(UtilityMeter::class);
    }

    public function leases(): HasManyThrough
    {
        return $this->hasManyThrough(Lease::class, Unit::class);
    }

    // ============ Derived metrics ============

    public function occupancyRate(): float
    {
        $total = $this->units()->count();
        if ($total === 0) {
            return 0;
        }
        $occupied = $this->units()->where('status', 'occupied')->count();
        return round(($occupied / $total) * 100, 1);
    }

    public function vacantUnitsCount(): int
    {
        return $this->units()->where('status', 'vacant')->count();
    }

    public function occupiedUnitsCount(): int
    {
        return $this->units()->where('status', 'occupied')->count();
    }
}
