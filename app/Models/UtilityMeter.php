<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[DeletionAllowed(reason: 'operational: soft-delete IS the retirement path, and the energy trend already excludes retired meters')]
#[PropertyOwned]
class UtilityMeter extends Model
{
    use HasFactory, HasSearchText, SoftDeletes;

    public const TYPES = ['electric', 'water', 'gas'];

    public const STATUSES = ['active', 'inactive', 'faulty'];

    protected $fillable = [
        'asset_id',
        'unit_id',
        'meter_number',
        'type',
        'provider',
        'status',
        'unit_of_measurement',
        'rate_per_unit',
    ];

    /**
     * The number stamped on the meter, and the utility provider.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->meter_number,
            $this->provider,
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function readings(): HasMany
    {
        return $this->hasMany(MeterReading::class)->orderBy('reading_date');
    }

    public function latestReading(): ?MeterReading
    {
        return $this->readings()->orderByDesc('reading_date')->first();
    }

    public function isCommonArea(): bool
    {
        return $this->unit_id === null;
    }
}
