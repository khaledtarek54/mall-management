<?php

namespace App\Models;

use App\Contracts\BillableAgreement;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[DeletionAllowed(reason: 'operational: voided through the pool, not removed')]
#[PropertyOwned(via: 'pool')]
class CamAllocation extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUSES = ['pending', 'billed', 'disputed', 'closed'];

    protected $fillable = [
        'cam_expense_pool_id',
        'lease_id',
        'unit_ownership_id',
        'pro_rata_share_pct',
        'allocated_amount',
        'excluded_amount',
        'estimated_paid',
        'true_up_amount',
        'admin_fee_amount',
        'admin_fee_vat_amount',
        'cap_amount',
        'capped_cost_amount',
        'cap_absorbed_amount',
        'cap_headroom_used',
        'cap_headroom_banked',
        'proposed_monthly_estimate',
        'estimate_applied_at',
        'exclusions',
        'status',
        'billed_charge_id',
        'billed_credit_note_id',
        'billed_admin_fee_charge_id',
    ];

    protected $casts = [
        'cap_headroom_used' => 'decimal:2',
        'cap_headroom_banked' => 'decimal:2',
        'proposed_monthly_estimate' => 'decimal:2',
        'estimate_applied_at' => 'datetime',
        'pro_rata_share_pct' => 'decimal:4',
        'allocated_amount' => 'decimal:2',
        'excluded_amount' => 'decimal:2',
        'estimated_paid' => 'decimal:2',
        'true_up_amount' => 'decimal:2',
        'admin_fee_amount' => 'decimal:2',
        'admin_fee_vat_amount' => 'decimal:2',
        'cap_amount' => 'decimal:2',
        'capped_cost_amount' => 'decimal:2',
        'cap_absorbed_amount' => 'decimal:2',
        'exclusions' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(fn (self $a) => $a->assertBelongsToExactlyOneAgreement());
    }

    /** @return BelongsTo<CamExpensePool, $this> */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(CamExpensePool::class, 'cam_expense_pool_id');
    }

    /** @return BelongsTo<Lease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /**
     * The unit ownership this share belongs to — null for a lease allocation.
     *
     * @return BelongsTo<UnitOwnership, $this>
     */
    public function unitOwnership(): BelongsTo
    {
        return $this->belongsTo(UnitOwnership::class);
    }

    /**
     * WHO this share belongs to, without the caller asking which kind.
     *
     * Both implement `BillableAgreement`, so the true-up can be billed to either through the same
     * seam. Callers that need lease LAW — a ceiling, a stated share, banked headroom — must still
     * ask for `lease` explicitly and handle its absence: a sale carries no CAM clause, which is the
     * whole reason an ownership takes the plain pro-rata path.
     */
    public function agreement(): ?BillableAgreement
    {
        return $this->lease ?? $this->unitOwnership;
    }

    /**
     * The FK that ties a document raised for this allocation back to its agreement.
     *
     * Used for the anchor `Charge`, and to find the agreement's open invoices when an over-recovery
     * is credited. Returned as an array so a caller never has to branch on which kind it is.
     *
     * @return array<string, int>
     */
    public function chargeLink(): array
    {
        return $this->lease_id !== null
            ? ['lease_id' => $this->lease_id]
            : ['unit_ownership_id' => $this->unit_ownership_id];
    }

    /**
     * Exactly one agreement — a lease OR an ownership, never both, never neither.
     *
     * On the model rather than as a CHECK constraint: SQLite drops CHECKs on any later `->change()`
     * to the table, and this table has just had one. "Neither" is the silent case — an allocation
     * attached to nothing still counts toward the pool's tie-out while belonging to no party, so
     * the pool would reconcile perfectly and bill nobody.
     *
     * @throws \DomainException
     */
    public function assertBelongsToExactlyOneAgreement(): void
    {
        if (($this->lease_id !== null) === ($this->unit_ownership_id !== null)) {
            throw new \DomainException(__('admin.errors.cam_allocation_needs_one_agreement'));
        }
    }

    public function billedCharge(): BelongsTo
    {
        return $this->belongsTo(Charge::class, 'billed_charge_id');
    }

    /** Set instead of billedCharge when the true-up is a credit (negative). */
    public function billedCreditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class, 'billed_credit_note_id');
    }

    public function isBilled(): bool
    {
        return $this->status === 'billed';
    }

    public function isOverPaid(): bool
    {
        return (float) $this->true_up_amount < 0;
    }
}
