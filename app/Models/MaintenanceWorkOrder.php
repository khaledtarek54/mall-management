<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
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

    protected $fillable = [
        'maintenance_plan_id',
        'asset_id',
        'unit_id',
        'reference',
        'title',
        'category',
        'status',
        'scheduled_for',
        'completed_at',
        'completed_by_user_id',
        'department_id',
        'vendor_id',
        'notes',
    ];

    protected $casts = [
        'scheduled_for' => 'date',
        'completed_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'open',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['maintenance_plan_id', 'asset_id', 'unit_id', 'title', 'category', 'status', 'scheduled_for', 'completed_at'])
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

    public function items(): HasMany
    {
        return $this->hasMany(MaintenanceWorkOrderItem::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::TERMINAL);
    }

    public static function generateReference(string $assetCode = 'GEN', ?\DateTimeInterface $date = null): string
    {
        $date = $date ? Carbon::instance($date) : now();
        $prefix = sprintf('WO-%s-%s-', $assetCode, $date->format('Ym'));

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
                $order->reference = static::generateReference($order->asset?->code ?: 'GEN', $order->scheduled_for);
            }
        });
    }
}
