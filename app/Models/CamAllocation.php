<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CamAllocation extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUSES = ['pending', 'billed', 'disputed', 'closed'];

    protected $fillable = [
        'cam_expense_pool_id',
        'lease_id',
        'pro_rata_share_pct',
        'allocated_amount',
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
        'estimated_paid' => 'decimal:2',
        'true_up_amount' => 'decimal:2',
        'admin_fee_amount' => 'decimal:2',
        'admin_fee_vat_amount' => 'decimal:2',
        'cap_amount' => 'decimal:2',
        'capped_cost_amount' => 'decimal:2',
        'cap_absorbed_amount' => 'decimal:2',
        'exclusions' => 'array',
    ];

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

    public function billedCharge(): BelongsTo
    {
        return $this->belongsTo(Charge::class, 'billed_charge_id');
    }

    /** Set instead of billedCharge when the true-up is a credit (negative). */
    public function billedCreditNote(): BelongsTo
    {
        return $this->belongsTo(\App\Models\CreditNote::class, 'billed_credit_note_id');
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
