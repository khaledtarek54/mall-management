<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * سداد فاتورة مورد — a payment against a vendor bill (money leaving cash/bank).
 * Saving/deleting one re-derives the parent bill's paid_amount/balance/status.
 *
 * Soft-deletes so a deleted payment self-heals its GL: the sync-ledger sweep voids
 * a trashed source's journal entry, whereas a hard delete would orphan it (F7).
 */
class VendorBillPayment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'vendor_bill_id',
        'reference',
        'amount',
        'withholding_amount',
        'method',
        'payment_date',
        'notes',
        'created_by_user_id',
    ];

    /**
     * Cash that actually left the bank: the gross settlement minus tax withheld for the ETA.
     *
     * `amount` is what discharges the vendor's claim; this is what the bank statement shows.
     * They differ by exactly `withholding_amount` (خصم وإضافة, module 12b).
     */
    public function netPaid(): float
    {
        return round((float) $this->amount - (float) $this->withholding_amount, 2);
    }

    protected $casts = [
        'amount' => 'decimal:2',
        'withholding_amount' => 'decimal:2',
        'payment_date' => 'date',
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(VendorBill::class, 'vendor_bill_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected static function booted(): void
    {
        // Coerce a blank amount to 0 (NOT-NULL column) and keep the parent bill's
        // derived totals in lockstep with its payments. Read the RAW attribute — a
        // decimal:2 cast throws MathException if '' is read through the getter.
        static::saving(function (self $payment) {
            $raw = $payment->getAttributes()['amount'] ?? null;
            if ($raw === null || $raw === '') {
                $payment->amount = 0;
            }
        });

        static::saved(fn (self $payment) => $payment->bill?->recompute());
        static::deleted(fn (self $payment) => $payment->bill?->recompute());
    }
}
