<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A recurring preventive-maintenance schedule (module 26) — "what to check, how often,
 * where". When due, the `maintenance:generate-preventive` scan raises a work order with
 * this plan's checklist, then advances `next_due_date` by the frequency.
 */
class MaintenancePlan extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const FREQUENCY_UNITS = ['days', 'weeks', 'months'];

    protected $fillable = [
        'asset_id',
        'unit_id',
        'title',
        'category',
        'description',
        'frequency_unit',
        'frequency_value',
        'checklist',
        'department_id',
        'vendor_id',
        'next_due_date',
        'last_generated_at',
        'is_active',
    ];

    protected $casts = [
        'checklist' => 'array',
        'next_due_date' => 'date',
        'last_generated_at' => 'datetime',
        'is_active' => 'boolean',
        'frequency_value' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['asset_id', 'unit_id', 'title', 'category', 'frequency_unit', 'frequency_value', 'next_due_date', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('maintenance_plan');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(MaintenanceWorkOrder::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Active plans whose next occurrence is on/before the given date (default today). */
    public function scopeDue(Builder $query, ?string $onOrBefore = null): Builder
    {
        return $query->where('is_active', true)
            ->whereDate('next_due_date', '<=', $onOrBefore ?? now()->toDateString());
    }

    /** Advance next_due_date by the plan's frequency (in-memory; caller persists). */
    public function advanceDue(): void
    {
        $base = CarbonImmutable::parse($this->next_due_date);
        $step = max(1, (int) $this->frequency_value);

        $this->next_due_date = (match ($this->frequency_unit) {
            'days' => $base->addDays($step),
            'weeks' => $base->addWeeks($step),
            default => $base->addMonths($step),
        })->toDateString();

        $this->last_generated_at = now();
    }

    protected static function booted(): void
    {
        // frequency_value must be at least 1, else the plan would never advance.
        static::saving(function (self $plan) {
            if ((int) $plan->frequency_value < 1) {
                $plan->frequency_value = 1;
            }
        });
    }
}
