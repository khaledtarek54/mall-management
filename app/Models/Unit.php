<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'asset_id',
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

    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class);
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

    // Get display name like "Haya Walk · A-01"
    public function fullName(): string
    {
        return "{$this->asset->name} · {$this->code}";
    }
}
