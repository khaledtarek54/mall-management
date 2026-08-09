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

    /** Shares divide by the summed area of the participating leases — legacy, and the default. */
    public const DENOMINATOR_OCCUPIED = 'occupied';

    /** Shares divide by the property's gross leasable area, so vacancy sits with the landlord. */
    public const DENOMINATOR_GLA = 'gla';

    /** Shares divide by a contractually pinned m² figure. */
    public const DENOMINATOR_FIXED = 'fixed';

    protected $fillable = [
        'asset_id',
        'period_year',
        'total_actual_expense',
        'total_estimated_collected',
        'expense_basis',
        'estimate_basis',
        'denominator_basis',
        'denominator_fixed_sqm',
        'denominator_used_sqm',
        'landlord_unrecovered_amount',
        'gross_up_pct',
        'variable_pct',
        'controllable_pct',
        'grossed_up_expense',
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
        'denominator_fixed_sqm' => 'decimal:2',
        'denominator_used_sqm' => 'decimal:2',
        'landlord_unrecovered_amount' => 'decimal:2',
        'gross_up_pct' => 'decimal:2',
        'variable_pct' => 'decimal:2',
        'controllable_pct' => 'decimal:2',
        'grossed_up_expense' => 'decimal:2',
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

    /** @return BelongsTo<Asset, $this> */
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
        return $this->belongsToMany(LedgerAccount::class, 'cam_pool_accounts')
            ->withPivot('cost_nature', 'is_controllable')
            ->withTimestamps();
    }

    /**
     * The expense the shares are actually apportioned over (story RC-04).
     *
     * Without gross-up this is simply `total_actual_expense`, which is every pool that exists
     * today. With it, the VARIABLE portion is scaled to the occupancy assumption first:
     *
     *     basis = fixed + variable × (assumption ÷ actual occupancy)
     *
     * so a tenant pays what they would pay in a full centre rather than a discount funded by their
     * neighbours' vacancy. Fixed costs are never grossed — a security contract costs the same empty
     * or full, and the vacancy share of it is genuinely the landlord's.
     *
     * **Refused on an `occupied` denominator.** There the shares already sum to 100%, so grossing
     * up would bill tenants MORE than the landlord spent. Returning the ungrossed total is the
     * honest answer rather than quietly over-recovering; the form hides the field on that basis and
     * the statement never claims a gross-up that did not happen.
     *
     * Never scales DOWN: an occupancy already above the assumption means the mall is fuller than
     * the clause contemplated, and the tenants' own shares already reflect that.
     */
    public function apportionmentBasis(float $actualOccupancyPct): float
    {
        $total = (float) $this->total_actual_expense;
        $assumption = $this->gross_up_pct !== null ? (float) $this->gross_up_pct : 0.0;

        if ($assumption <= 0
            || $this->denominator_basis === self::DENOMINATOR_OCCUPIED
            || $actualOccupancyPct <= 0
            || $actualOccupancyPct >= $assumption) {
            return round($total, 2);
        }

        $variableShare = $this->variableShare();

        if ($variableShare <= 0) {
            return round($total, 2);
        }

        $variable = $total * $variableShare;
        $fixed = $total - $variable;

        return round($fixed + $variable * ($assumption / $actualOccupancyPct), 2);
    }

    /**
     * How much of the pool is variable, as a fraction.
     *
     * Derived from the attached accounts when the expense came from the ledger — the accounts know
     * their own nature — and read from `variable_pct` when the total was typed. A pool that says
     * nothing is 0% variable, so gross-up does nothing rather than something arbitrary.
     */
    public function variableShare(): float
    {
        if ($this->expense_basis === self::BASIS_LEDGER && $this->ledgerAccounts->isNotEmpty()) {
            return app(\App\Services\SyncCamPoolFromLedgerService::class)->variableShareFromLedger($this);
        }

        return $this->variable_pct !== null ? (float) $this->variable_pct / 100 : 0.0;
    }

    /**
     * How much of the pool is CONTROLLABLE, as a fraction (story RC-07).
     *
     * A different axis from {@see variableShare()}: a security contract is fixed AND controllable,
     * utilities are variable and NOT controllable, insurance is fixed and not controllable.
     * Conflating the two would cap the wrong half of the pool.
     *
     * Defaults to 1.0 — everything controllable — which is what makes a controllable-scoped cap on
     * an unclassified pool behave exactly like the unscoped cap it replaces.
     */
    public function controllableShare(): float
    {
        if ($this->expense_basis === self::BASIS_LEDGER && $this->ledgerAccounts->isNotEmpty()) {
            return app(\App\Services\SyncCamPoolFromLedgerService::class)->controllableShareFromLedger($this);
        }

        return $this->controllable_pct !== null ? (float) $this->controllable_pct / 100 : 1.0;
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
