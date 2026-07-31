<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A facility zone within a mall (module 30) — Ground Floor, Food Court, Parking,
 * Roof Plant, and the like. Each zone stands in exactly one property (direct
 * `asset_id`, like Unit / Warehouse / Equipment) and carries a `code` unique
 * within that property.
 *
 * The zone's purpose is routing: a set of supervisor Users is attached so that,
 * in a later slice, incoming requests / work orders can be dispatched to the
 * people responsible for that part of the mall. A supervisor may cover many
 * areas; an area may have many supervisors (BelongsToMany via `area_user`).
 */
class Area extends Model
{
    use HasFactory, HasSearchText, LogsActivity, SoftDeletes;

    protected $fillable = [
        'asset_id',
        'name',
        'code',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** NOT-NULL with no form field on some paths — never let a blank toggle send null. */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Zone name and code — 'Zone B', 'FC-01'.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->name,
            $this->code,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['asset_id', 'name', 'code', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('area');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** Staff responsible for this zone (FR routing, later slice). */
    public function supervisors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'area_user')->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
