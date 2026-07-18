<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A violation recorded against a tenant (module 31) — FR-REQ-15/16/17.
 *
 * Eltizam records that a tenant breached a mall rule (blocked fire exit,
 * unauthorised signage, after-hours noise, …) with an optional associated
 * cost/fine. Each violation stands in exactly one property (direct `asset_id`,
 * like Unit / Area / Equipment) and is raised against the SHARED Tenant (like
 * Invoice / Lease — the tenant may lease in several malls; the violation is
 * pinned to the mall where it happened).
 *
 * SCOPE. `fine_amount` RECORDS the money assessed (FR-REQ-15) — it is not billed:
 * this model never touches Invoice / Charge / GL. `notified_at` records when the
 * operator sent the tenant a notice (FR-REQ-17); the notice is an explicit
 * action ({@see \App\Services\SendViolationNoticeAction}), never on create. The
 * lifecycle is intentionally minimal: `open` → `resolved`.
 */
class Violation extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_RESOLVED,
    ];

    protected $fillable = [
        'asset_id',
        'tenant_id',
        'description',
        'fine_amount',
        'violation_date',
        'status',
        'notified_at',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'fine_amount' => 'decimal:2',
        'violation_date' => 'date',
        'notified_at' => 'datetime',
    ];

    /**
     * Status is NOT-NULL with a DB default; mirror it in the model so a create
     * that omits the field (or a blank Select) never sends null into the column.
     * `fine_amount` stays nullable by design (a violation may carry no fine).
     */
    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['asset_id', 'tenant_id', 'description', 'fine_amount', 'violation_date', 'status', 'notified_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('violation');
    }

    /**
     * Human-friendly reference for the table + notices (no stored reference
     * column — the id is the natural key). Read-only, display-only.
     */
    public function getReferenceAttribute(): string
    {
        return 'VIO-'.str_pad((string) ($this->id ?? 0), 5, '0', STR_PAD_LEFT);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** True once a tenant notice has been sent (FR-REQ-17). */
    public function isNotified(): bool
    {
        return $this->notified_at !== null;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
