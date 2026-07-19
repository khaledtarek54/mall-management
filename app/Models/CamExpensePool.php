<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class CamExpensePool extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUSES = ['draft', 'reconciling', 'reconciled', 'closed'];

    protected $fillable = [
        'asset_id',
        'period_year',
        'total_actual_expense',
        'total_estimated_collected',
        'admin_fee_pct',
        'admin_fee_on_net',
        'status',
        'notes',
        'reconciled_at',
        'reconciled_by_user_id',
    ];

    protected $casts = [
        'total_actual_expense' => 'decimal:2',
        'total_estimated_collected' => 'decimal:2',
        'admin_fee_pct' => 'decimal:4',
        'admin_fee_on_net' => 'boolean',
        'reconciled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Freeze the recovery basis once billing has started. If the actual-expense
        // or estimated-collected figure changes after ANY allocation is billed,
        // generateAllocations recomputes only the still-pending allocations against
        // the new figure while billed ones keep the old amount — silently over- or
        // under-recovering the pool. Correcting a reconciled figure requires voiding
        // the billed allocations first (docs/modules/08-cam.md §8).
        static::updating(function (CamExpensePool $pool) {
            // admin_fee_pct/admin_fee_on_net are recovery-basis inputs too: changing the fee rate
            // after some allocations are billed would leave billed rows on the old rate while
            // re-generated pending rows recompute on the new one → different fees for equal shares
            // in one pool. Freeze them alongside the expense/estimate basis.
            $basisChanged = $pool->isDirty('total_actual_expense')
                || $pool->isDirty('total_estimated_collected')
                || $pool->isDirty('admin_fee_pct')
                || $pool->isDirty('admin_fee_on_net');

            if ($basisChanged && $pool->allocations()->where('status', '!=', 'pending')->exists()) {
                throw new \DomainException(
                    'Cannot change the CAM recovery basis once an allocation has been billed — void the billed allocations first.'
                );
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'total_actual_expense', 'total_estimated_collected', 'admin_fee_pct', 'reconciled_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('cam_pool');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CamAllocation::class);
    }

    public function reconciledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by_user_id');
    }

    public function variance(): float
    {
        return (float) $this->total_actual_expense - (float) $this->total_estimated_collected;
    }

    public function isReconciled(): bool
    {
        return in_array($this->status, ['reconciled', 'closed']);
    }
}
