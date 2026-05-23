<?php

namespace App\Models;

use App\Models\Scopes\CurrentOperatorScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ScopedBy([CurrentOperatorScope::class])]
class Asset extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'operator_id',
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

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
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
