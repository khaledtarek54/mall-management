<?php

namespace App\Models;

use App\Models\Concerns\RefusesDeletionWhenReferenced;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class CamExpensePool extends Model
{
    use RefusesDeletionWhenReferenced, HasFactory, LogsActivity, SoftDeletes;

    public const STATUSES = ['draft', 'reconciling', 'reconciled', 'closed'];

    /** The pool's totals are typed by a human — the legacy behaviour, and the default. */
    public const BASIS_STATED = 'stated';

    /** `total_actual_expense` is summed from posted GL lines on the pool's accounts (RC-01). */
    public const BASIS_LEDGER = 'ledger';

    /** `total_estimated_collected` is what tenants were actually invoiced in the year (RC-05). */
    public const BASIS_BILLED = 'billed';

    /**
     * Invoice line types that ARE the monthly CAM estimate.
     *
     * `service_charge` is what Atriom bills monthly and reconciles annually; `cam_recovery` and
     * `cam_admin_fee` are the RESULT of a reconciliation and must never be counted as estimates
     * paid, or last year's true-up would inflate this year's estimate and the pool would chase its
     * own tail.
     */
    public const ESTIMATE_ITEM_TYPES = ['service_charge'];

    protected $fillable = [
        'asset_id',
        'period_year',
        'total_actual_expense',
        'total_estimated_collected',
        'expense_basis',
        'estimate_basis',
        'admin_fee_pct',
        'admin_fee_on_net',
        'recovery_vat_rate',
        'status',
        'notes',
        'reconciled_at',
        'reconciled_by_user_id',
    ];

    protected $casts = [
        'expense_synced_at' => 'datetime',
        'total_actual_expense' => 'decimal:2',
        'total_estimated_collected' => 'decimal:2',
        'admin_fee_pct' => 'decimal:4',
        'admin_fee_on_net' => 'boolean',
        'recovery_vat_rate' => 'decimal:2',
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
                || $pool->isDirty('admin_fee_on_net')
                || $pool->isDirty('recovery_vat_rate'); // changing the recovery VAT after billing would leave billed rows on the old rate

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
            ->logOnly(['status', 'total_actual_expense', 'total_estimated_collected', 'admin_fee_pct', 'recovery_vat_rate', 'reconciled_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('cam_pool');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * The GL accounts whose posted expense makes up this pool (RC-01).
     *
     * Empty on every pool created before this existed, which is exactly why `expense_basis` defaults
     * to `stated`: a pool with no accounts and a ledger basis would recover nothing.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<LedgerAccount, $this>
     */
    public function ledgerAccounts(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(LedgerAccount::class, 'cam_pool_accounts')->withTimestamps();
    }

    /** Is either total derived rather than typed? */
    public function isDerived(): bool
    {
        return $this->expense_basis === self::BASIS_LEDGER
            || $this->estimate_basis === self::BASIS_BILLED;
    }

    /** @return HasMany<CamAllocation, $this> */
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
