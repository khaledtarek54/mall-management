<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A depreciable asset the operator owns (module 23), scoped to one property.
 * Depreciation is straight-line: (acquisition_cost − salvage_value) spread over
 * useful_life_months. Accumulated depreciation is DERIVED from depreciation_entries
 * (DepreciationService), never a cached column.
 */
class FixedAsset extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'asset_id',
        'name',
        'tag',
        'category',
        'acquisition_date',
        'acquisition_cost',
        'salvage_value',
        'useful_life_months',
        'method',
        'funded_from',
        'status',
        'disposed_on',
        'notes',
    ];

    protected $casts = [
        'acquisition_date' => 'date',
        'disposed_on' => 'date',
        'acquisition_cost' => 'decimal:2',
        'salvage_value' => 'decimal:2',
        'useful_life_months' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['asset_id', 'name', 'tag', 'acquisition_cost', 'salvage_value', 'useful_life_months', 'status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('fixed_asset');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function depreciationEntries(): HasMany
    {
        return $this->hasMany(DepreciationEntry::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    protected static function booted(): void
    {
        // NOT-NULL guard for the money columns (the meter_readings.cost bug class).
        static::saving(function (self $fixedAsset) {
            foreach (['acquisition_cost', 'salvage_value'] as $column) {
                $raw = $fixedAsset->getAttributes()[$column] ?? null;
                if ($raw === null || $raw === '') {
                    $fixedAsset->{$column} = 0;
                }
            }
        });
    }
}
