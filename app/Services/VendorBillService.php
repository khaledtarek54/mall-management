<?php

namespace App\Services;

use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Vendor-bill (Accounts Payable) lifecycle: approve → record payment(s) → paid.
 * Recognition on the GL happens through VendorBillJournalizer once the bill is
 * past draft; this service only manages the document + its payments (paid_amount/
 * balance are re-derived by VendorBill::recompute).
 */
class VendorBillService
{
    /** Move a draft bill to `approved` (idempotent). Once approved it is GL-postable. */
    public function approve(VendorBill $bill): VendorBill
    {
        if ($bill->status !== 'draft') {
            return $bill;
        }

        return DB::transaction(function () use ($bill) {
            $bill->status = 'approved';
            $bill->approved_by_user_id = Auth::id();
            $bill->approved_at = now();
            $bill->save();
            $bill->recompute();

            return $bill->refresh();
        });
    }

    /**
     * Record a payment against a bill. Lock-safe: locks the bill and caps the
     * amount at the remaining balance, so concurrent payments can't over-pay.
     * Returns the actual amount paid (0 if nothing applied).
     */
    public function recordPayment(VendorBill $bill, float $amount, string $method = 'bank_transfer', ?\DateTimeInterface $date = null, ?string $notes = null): float
    {
        return DB::transaction(function () use ($bill, $amount, $method, $date, $notes) {
            $bill = VendorBill::query()->with('vendor')->lockForUpdate()->find($bill->id);

            if (! $bill || ! $bill->isPostable()) {
                return 0.0; // draft/cancelled bills can't be paid
            }

            $pay = round(min($amount, (float) $bill->balance), 2);
            if ($pay <= 0) {
                return 0.0;
            }

            // Egyptian withholding tax (خصم وإضافة). The vendor's payable is settled in FULL by
            // $pay — part in cash, part by tax paid to the ETA on their behalf — so `amount` stays
            // gross and the bill's balance arithmetic is unchanged. Only the cash leg shrinks.
            // Rate resolution + the on/off switch live in App\Support\WithholdingTax (settings-
            // driven; a guessed statutory rate hardcoded here would look official and be wrong).
            $withheld = \App\Support\WithholdingTax::on($pay, $bill->vendor);

            VendorBillPayment::create([
                'vendor_bill_id' => $bill->id,
                'amount' => $pay,
                'withholding_amount' => $withheld,
                'method' => $method,
                'payment_date' => $date ? \Illuminate\Support\Carbon::instance($date)->toDateString() : now()->toDateString(),
                'notes' => $notes,
                'created_by_user_id' => Auth::id(),
            ]); // its saved() hook recomputes the bill

            return $pay;
        });
    }

    /** Cancel a bill. Refuses if any payment has been recorded (reverse those first). */
    public function cancel(VendorBill $bill): VendorBill
    {
        if ($bill->status === 'cancelled') {
            return $bill;
        }
        if ((float) $bill->paid_amount > 0) {
            throw new \DomainException('Cannot cancel a bill that has payments. Reverse the payments first.');
        }

        return DB::transaction(function () use ($bill) {
            $bill->status = 'cancelled';
            $bill->save();      // persist the status change (activity-logged)
            $bill->recompute(); // zeroes the balance via the cancelled branch — the single source of truth

            return $bill->refresh();
        });
    }
}
