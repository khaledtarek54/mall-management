<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One checklist item on a preventive-maintenance work order (module 26). Copied from
 * the plan's checklist template when the order is raised; the engineer marks it
 * pass/fail during the visit.
 *
 * `result` is the single source of truth for an item's state (FR-PPM-07). An item is
 * *marked* once it is anything other than `pending` — a fail is just as marked as a
 * pass, and the work order's completion gate counts marked items, not passing ones
 * (a visit that finds a fault is still a completed visit; the fault becomes a CM).
 */
class FacilityWorkOrderItem extends Model
{
    use HasFactory;

    public const RESULT_PENDING = 'pending';

    public const RESULT_PASS = 'pass';

    public const RESULT_FAIL = 'fail';

    /** Values an engineer may set. `pending` is the unset default, not a choice. */
    public const MARKED_RESULTS = [self::RESULT_PASS, self::RESULT_FAIL];

    public const RESULTS = [self::RESULT_PENDING, self::RESULT_PASS, self::RESULT_FAIL];

    protected $fillable = [
        'facility_work_order_id',
        'label',
        'result',
        'marked_at',
        'marked_by_user_id',
    ];

    protected $casts = [
        'marked_at' => 'datetime',
    ];

    protected $attributes = [
        'result' => self::RESULT_PENDING,
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(FacilityWorkOrder::class, 'facility_work_order_id');
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by_user_id');
    }

    /** Corrective jobs raised because this check failed (FR-CM-01) — normally 0 or 1. */
    public function correctiveWorkOrders(): HasMany
    {
        return $this->hasMany(FacilityWorkOrder::class, 'source_item_id');
    }

    /** Has the engineer recorded an outcome yet? Pass and fail both count. */
    public function isMarked(): bool
    {
        return $this->result !== self::RESULT_PENDING;
    }

    public function hasFailed(): bool
    {
        return $this->result === self::RESULT_FAIL;
    }

    /**
     * Back-compat accessor for the old `is_done` boolean, which this model replaced
     * with `result`. Reads only — write `result`.
     */
    public function getIsDoneAttribute(): bool
    {
        return $this->isMarked();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('result', self::RESULT_PENDING);
    }

    public function scopeMarked(Builder $query): Builder
    {
        return $query->whereIn('result', self::MARKED_RESULTS);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('result', self::RESULT_FAIL);
    }
}
