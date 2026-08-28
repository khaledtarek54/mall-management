<?php

namespace App\Services;

use App\Models\SlaPenalty;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Support\PostingDate;
use App\Support\ReversalReason;
use App\Support\WithholdingTax;
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

        // Approval recognises the payable on the GL dated by `bill_date` (VendorBillJournalizer). If
        // that period has since closed, the recognition would silently fail (the F-89/F-93 class) —
        // the bill would read `approved` while the GL never posted the AP. Refuse it so the operator
        // re-dates the still-editable draft into an open period first. A missing period is allowed.
        PostingDate::assertOpen($bill->bill_date, __('admin.fields.bill_date'));

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
    public function recordPayment(VendorBill $bill, float $amount, string $method = 'bank_transfer', ?\DateTimeInterface $date = null, ?string $notes = null, ?int $bankAccountId = null): float
    {
        // `payment_date` becomes the GL entry_date (VendorBillPaymentJournalizer), so it must land in
        // an OPEN period — otherwise the Dr AP / Cr Bank / Cr WHT-Payable posting silently fails (the
        // F-89/F-93 class) while the bill reads "paid" and the WHT liability is understated. Guard in
        // the SERVICE so the API/console paths are covered too, mirroring the AR receipt (CreatePayment).
        // A missing period is allowed; only a CLOSED one is refused. Returns the normalised date.
        $postingDate = PostingDate::assertOpen($date ?? now(), __('admin.fields.payment_date'));

        return DB::transaction(function () use ($bill, $amount, $method, $postingDate, $notes, $bankAccountId) {
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
            //
            // `onBillPayment`, NOT `on`: the WHT base excludes VAT, and `$pay` is capped at the
            // balance, which comes from `total` — net PLUS VAT. Passing it to the primitive
            // over-withheld on every VAT-bearing bill (3,420 instead of 3,000 at 3% on 100,000).
            $withheld = WithholdingTax::onBillPayment($pay, $bill);

            VendorBillPayment::create([
                'vendor_bill_id' => $bill->id,
                'amount' => $pay,
                'withholding_amount' => $withheld,
                'method' => $method,
                // Which bank account the money left — null means the rail decides, as before.
                'bank_account_id' => $bankAccountId,
                'payment_date' => $postingDate->toDateString(),
                'notes' => $notes,
                'created_by_user_id' => Auth::id(),
            ]); // its saved() hook recomputes the bill

            return $pay;
        });
    }

    /** Cancel a bill. Refuses if any payment has been recorded (reverse those first). */
    public function cancel(VendorBill $bill, ?string $reason = null): VendorBill
    {
        if ($bill->status === 'cancelled') {
            return $bill;
        }
        // `paid_amount` already excludes voided payments (VendorBill::recompute), so a bill whose
        // payments have all been voided cancels cleanly — which is what makes this refusal a real
        // instruction rather than a dead end. It was one until VoidVendorBillPaymentService existed.
        if ((float) $bill->paid_amount > 0) {
            throw new \DomainException(__('admin.refusals.bill_cancel_has_payments'));
        }

        return DB::transaction(function () use ($bill, $reason) {
            // Release any SLA penalty applied to this bill back to `final`. A cancelled bill owes
            // nothing, so an applied penalty pointing at it would be silently dropped — still owed by
            // the vendor, but no longer chargeable or collectable. Un-applying returns it to the pool
            // to be re-charged to another bill (the canonical un-apply, which also re-derives the bill).
            $applied = $bill->penalties()->where('status', SlaPenalty::STATUS_APPLIED)->get();
            foreach ($applied as $penalty) {
                /** @var SlaPenalty $penalty */
                app(ApplySlaPenaltyService::class)->detach($penalty);
            }

            // `vendor_bills` has no `notes` column — the audit row is the whole record.
            $bill->status = 'cancelled';
            $bill->save();      // persist the status change (activity-logged)
            $bill->recompute(); // zeroes the balance via the cancelled branch — the single source of truth

            ReversalReason::record($bill, 'cancelled', $reason);

            return $bill->refresh();
        });
    }
}
