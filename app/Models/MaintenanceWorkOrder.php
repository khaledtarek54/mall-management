<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A preventive-maintenance work order (module 26) — a scheduled facility job raised from
 * a plan or ad-hoc. Carries a checklist (its items); once the work is done the order is
 * marked `done` (a terminal, immutable state, like closed maintenance requests).
 */
class MaintenanceWorkOrder extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUSES = ['open', 'in_progress', 'done', 'cancelled'];

    public const TERMINAL = ['done', 'cancelled'];

    /** Planned/preventive vs corrective (FR-CM-01). */
    public const TYPE_PPM = 'ppm';

    public const TYPE_CM = 'cm';

    public const TYPES = [self::TYPE_PPM, self::TYPE_CM];

    /** FR-CM-02 — in-house staff vs a third-party company. CM only. */
    public const EXECUTION_INTERNAL = 'internal';

    public const EXECUTION_EXTERNAL = 'external';

    public const EXECUTION_TYPES = [self::EXECUTION_INTERNAL, self::EXECUTION_EXTERNAL];

    /**
     * FR-CM-06 asks for at least Normal + Urgent. This is the 4-tier superset module 11
     * already speaks (Normal ≈ medium), so the two halves of maintenance don't end up
     * disagreeing about what "urgent" means.
     */
    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    protected $fillable = [
        'maintenance_plan_id',
        'work_order_type',
        'execution_type',
        'asset_id',
        'unit_id',
        'equipment_id',
        'reference',
        'title',
        'category',
        'status',
        'priority',
        'scheduled_for',
        'acknowledged_at',
        'target_resolution_at',
        'sla_breach_notified_at',
        'completed_at',
        'completed_by_user_id',
        'department_id',
        'vendor_id',
        'assigned_to_user_id',
        'notes',
        'description',
        'source_item_id',
        'parent_work_order_id',
    ];

    protected $casts = [
        'scheduled_for' => 'date',
        'acknowledged_at' => 'datetime',
        'target_resolution_at' => 'datetime',
        'sla_breach_notified_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'open',
        'work_order_type' => self::TYPE_PPM,
        'priority' => 'medium',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['maintenance_plan_id', 'work_order_type', 'execution_type', 'asset_id', 'unit_id', 'equipment_id', 'title', 'category', 'status', 'priority', 'scheduled_for', 'acknowledged_at', 'target_resolution_at', 'completed_at', 'vendor_id', 'assigned_to_user_id', 'parent_work_order_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('maintenance_work_order');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class, 'maintenance_plan_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * The machine this job is against (FR-PPM-03) — copied from the plan when raised, or
     * set directly on an ad-hoc order. Carried here rather than read through the plan
     * because an order outlives its plan (nullOnDelete) and ad-hoc orders have none.
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    /** FR-CM-03 — the in-house technician on the job (internal CM). */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** FR-CM-01 — the failed check that triggered this CM, if any. */
    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(MaintenanceWorkOrderItem::class, 'source_item_id');
    }

    /** FR-CM-15 — the order this one follows up on (its fix was incomplete). */
    public function parentWorkOrder(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_work_order_id');
    }

    /** FR-CM-15 — follow-ups raised because this order's fix was incomplete. */
    public function followUps(): HasMany
    {
        return $this->hasMany(self::class, 'parent_work_order_id');
    }

    public function isCorrective(): bool
    {
        return $this->work_order_type === self::TYPE_CM;
    }

    public function scopeCorrective(Builder $query): Builder
    {
        return $query->where('work_order_type', self::TYPE_CM);
    }

    public function scopePreventive(Builder $query): Builder
    {
        return $query->where('work_order_type', self::TYPE_PPM);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MaintenanceWorkOrderItem::class);
    }

    /**
     * Checklist items the engineer marked as failed. These are what FR-CM-01 raises
     * corrective maintenance from — a failed PPM check is the canonical CM trigger.
     */
    public function failedItems(): HasMany
    {
        return $this->items()->failed();
    }

    /** FR-PPM-07 — does every checklist item carry an outcome yet? */
    public function checklistIsComplete(): bool
    {
        return ! $this->items()->pending()->exists();
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    /**
     * Past its SLA target and still open (FR-CM-08). Derived, not stored — an order with no
     * target (never accepted, or preventive) is never overdue.
     */
    public function isOverdue(): bool
    {
        return $this->target_resolution_at !== null
            && ! $this->isTerminal()
            && $this->target_resolution_at->isPast();
    }

    /** Whole hours past the SLA target; 0 when not overdue. */
    public function hoursOverSla(): int
    {
        if ($this->target_resolution_at === null || ! $this->target_resolution_at->isPast()) {
            return 0;
        }

        return (int) abs($this->target_resolution_at->diffInHours(now()));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::TERMINAL);
    }

    /**
     * `WO-{asset}-{YYYYMM}-{n}` for preventive, `CM-…` for corrective — an engineer can tell
     * a scheduled visit from a fault report by the reference alone (mirrors module 11's
     * per-type reference prefixes).
     */
    public static function generateReference(string $assetCode = 'GEN', ?\DateTimeInterface $date = null, string $type = self::TYPE_PPM): string
    {
        $date = $date ? Carbon::instance($date) : now();
        $prefix = sprintf('%s-%s-%s-', $type === self::TYPE_CM ? 'CM' : 'WO', $assetCode, $date->format('Ym'));

        $last = static::withTrashed()->where('reference', 'like', $prefix.'%')->orderByDesc('reference')->value('reference');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        // Bump until free — the bare max+1 is race-prone under concurrent creates
        // (mirrors TenantRequest::generateReference); the DB unique index is the backstop.
        $candidate = $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        while (static::withTrashed()->where('reference', $candidate)->exists()) {
            $candidate = $prefix.str_pad((string) ++$next, 4, '0', STR_PAD_LEFT);
        }

        return $candidate;
    }

    protected static function booted(): void
    {
        static::creating(function (self $order) {
            if (empty($order->reference)) {
                $order->reference = static::generateReference(
                    $order->asset?->code ?: 'GEN',
                    $order->scheduled_for,
                    $order->work_order_type ?? self::TYPE_PPM,
                );
            }
        });

        static::saving(function (self $order) {
            if (! in_array($order->work_order_type, self::TYPES, true)) {
                throw new InvalidArgumentException(
                    "Unknown work_order_type '{$order->work_order_type}'; expected one of: ".implode(', ', self::TYPES).'.'
                );
            }

            if ($order->work_order_type !== self::TYPE_CM) {
                return;
            }

            // ---- CM-only rules (FR-CM-02/03/04) ----------------------------------

            if (! in_array($order->execution_type, self::EXECUTION_TYPES, true)) {
                throw new InvalidArgumentException(
                    "A corrective work order must be classified internal or external (FR-CM-02); got '{$order->execution_type}'."
                );
            }

            if (blank($order->description)) {
                throw new InvalidArgumentException('A corrective work order requires a description (FR-CM-04).');
            }

            // FR-CM-02/03 as a real XOR. Module 11 allows a request to carry BOTH a staff
            // assignee and a vendor at once, which is exactly why its assignment could never
            // serve as the internal-vs-external discriminator (doc 11 §"Gotchas" says so
            // outright). Repeating that here would make execution_type decorative: the
            // classification has to constrain who is actually on the job.
            if ($order->execution_type === self::EXECUTION_INTERNAL && $order->vendor_id !== null) {
                throw new InvalidArgumentException('An internal corrective work order is handled in-house; it cannot also name a vendor.');
            }

            if ($order->execution_type === self::EXECUTION_EXTERNAL && $order->assigned_to_user_id !== null) {
                throw new InvalidArgumentException('An external corrective work order is handled by the vendor; it cannot also name an in-house technician.');
            }
        });
    }
}
