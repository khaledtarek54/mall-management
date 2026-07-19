<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One band of the approval ladder: "for this module, an amount in this range needs someone
 * holding this permission" (FR-CM-11, FR-PROC-02).
 *
 * Operator-wide, with no property dimension — unlike SLA, which the FRD explicitly wants
 * set per mall, approval authority is a company policy. Don't add `asset_id` speculatively.
 */
class ApprovalRule extends Model
{
    use HasFactory, LogsActivity;

    /** Approvable modules. A new one is a row + a constant, never a migration. */
    public const MODULE_INVENTORY_DRAW = 'inventory_draw';

    /**
     * FR-PROC-02 — "route procurement requests through an approval workflow before order
     * placement". The FRD's own note: "The client did not specify a formal approval hierarchy for
     * procurement itself. Confirm whether procurement approval also follows a price-based manager
     * hierarchy or a separate rule." We default to price-based, consistent with FR-CM-11, because
     * that is the only hierarchy the client HAS described — and because it is configuration, so
     * their answer is a row change rather than a rewrite. Flagged in BUSINESS-RULES.
     */
    public const MODULE_PURCHASE_REQUEST = 'purchase_request';

    /**
     * Owner distributions (module 32) — signing off a payout to a Jawad owner. Deliberately
     * has NO seeded bands: the operator's payout-approval policy is unknown, and inventing one
     * would be inventing policy (the same discipline the other bands follow). With no bands,
     * ApprovalPolicy treats a disbursement as needing no approval — the operator turns the gate
     * on by adding bands. Approval is operator-side (Eltizam signs off paying Jawad).
     */
    public const MODULE_DISBURSEMENT = 'disbursement';

    public const MODULES = [self::MODULE_INVENTORY_DRAW, self::MODULE_PURCHASE_REQUEST, self::MODULE_DISBURSEMENT];

    /**
     * The approval ladder, as permissions. Tiers rather than named roles so the ladder
     * composes with the existing RBAC instead of standing up a parallel one — a role gains
     * authority by being granted a tier.
     */
    public const TIER_1 = 'approvals.tier_1';

    public const TIER_2 = 'approvals.tier_2';

    public const TIER_3 = 'approvals.tier_3';

    public const TIERS = [self::TIER_1, self::TIER_2, self::TIER_3];

    protected $fillable = [
        'module',
        'min_amount',
        'max_amount',
        'required_permission',
        'is_active',
    ];

    protected $casts = [
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
        'min_amount' => 0,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['module', 'min_amount', 'max_amount', 'required_permission', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('approval_rule');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForModule(Builder $query, string $module): Builder
    {
        return $query->where('module', $module);
    }

    /** Does this band contain $amount? min inclusive, max exclusive, null max = unbounded. */
    public function covers(float $amount): bool
    {
        return $amount >= (float) $this->min_amount
            && ($this->max_amount === null || $amount < (float) $this->max_amount);
    }

    public function label(): string
    {
        $max = $this->max_amount === null ? '∞' : number_format((float) $this->max_amount, 2);

        return number_format((float) $this->min_amount, 2).' – '.$max;
    }

    protected static function booted(): void
    {
        static::saving(function (self $rule) {
            if (! in_array($rule->module, self::MODULES, true)) {
                throw new InvalidArgumentException(
                    "Unknown approval module '{$rule->module}'; expected one of: ".implode(', ', self::MODULES).'.'
                );
            }

            // An inverted band can never match anything, so it would silently disable
            // approval for its range rather than fail loudly.
            if ($rule->max_amount !== null && (float) $rule->max_amount <= (float) $rule->min_amount) {
                throw new InvalidArgumentException('An approval band\'s maximum must be greater than its minimum.');
            }

            if ((float) $rule->min_amount < 0) {
                throw new InvalidArgumentException('An approval band cannot start below zero.');
            }
        });
    }
}
