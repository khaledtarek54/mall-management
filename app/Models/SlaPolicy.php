<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * How long a corrective job of a given priority may take **at one property** (FR-CM-05/06).
 *
 * A row is an override, not a requirement: absent, the global default applies. An operator
 * therefore records only the malls that genuinely differ, instead of restating the same
 * four numbers for every property.
 */
class SlaPolicy extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'sla_policies';

    /** Mirrors FacilityWorkOrder::PRIORITIES — the tiers must not drift apart. */
    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    protected $fillable = [
        'asset_id',
        'priority',
        'resolve_hours',
        'respond_hours',
        'is_active',
    ];

    protected $casts = [
        'resolve_hours' => 'integer',
        'respond_hours' => 'integer',
        'is_active' => 'boolean',
    ];

    /** NOT-NULL — never let a blank toggle send null. */
    protected $attributes = [
        'is_active' => true,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['asset_id', 'priority', 'resolve_hours', 'respond_hours', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('sla_policy');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true);
    }

    protected static function booted(): void
    {
        static::saving(function (self $policy) {
            if (! in_array($policy->priority, self::PRIORITIES, true)) {
                throw new InvalidArgumentException(
                    "Unknown priority '{$policy->priority}'; expected one of: ".implode(', ', self::PRIORITIES).'.'
                );
            }

            // A zero-hour SLA would mark every job breached the instant it is accepted.
            // Nullable = "this property overrides only the resolution target"; a value of 0 is not
            // that, it is a deadline in the past on every job.
            if ($policy->respond_hours !== null && (int) $policy->respond_hours < 1) {
                throw new InvalidArgumentException('respond_hours must be at least 1 when set.');
            }

            if ((int) $policy->resolve_hours < 1) {
                throw new InvalidArgumentException('resolve_hours must be at least 1.');
            }
        });
    }
}
