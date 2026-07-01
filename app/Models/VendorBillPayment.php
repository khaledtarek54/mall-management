<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سداد فاتورة مورد — a payment against a vendor bill (money leaving cash/bank).
 * Saving/deleting one re-derives the parent bill's paid_amount/balance/status.
 */
class VendorBillPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_bill_id',
        'reference',
        'amount',
        'method',
        'payment_date',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
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
        // derived totals in lockstep with its payments.
        static::saving(function (self $payment) {
            if ($payment->amount === null || $payment->amount === '') {
                $payment->amount = 0;
            }
        });

        static::saved(fn (self $payment) => $payment->bill?->recompute());
        static::deleted(fn (self $payment) => $payment->bill?->recompute());
    }
}
