<?php

namespace App\Services;

use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Void a vendor payment — the AP mirror of {@see VoidPaymentService}, and the correction path two
 * existing refusals already promised without anything implementing it: `DeletionPolicy` names it
 * ("void the payment — money left the bank") and `VendorBillService::cancel` tells the operator to
 * "reverse the payments first".
 *
 * Sets `voided_at`: the payment's `saved` hook re-derives the bill (a voided row is excluded from
 * `paid_amount`, so the payable re-opens and the status falls back from `paid`), and the ledger leg
 * (Dr AP / Cr Bank / Cr Withholding-tax payable) is reversed by the real-time sync or the sweep,
 * because the journalizer returns no payload for a voided payment.
 *
 * **Why a status flip and not a delete.** Money records are never deletable, and that is the point
 * here rather than a technicality: the void is the document an auditor follows from a bank statement
 * that shows no such payment back to the operator who cancelled the cheque and said why. The row
 * stays, carrying its reason, its author and the reversing journal entry.
 *
 * **What it deliberately does not do.** It does not un-cancel the bill, re-open a closed period, or
 * refuse a payment whose entry sits in one. A reversal lands in the original period when that is
 * still open and in today's otherwise — the standing rule for every void in the system
 * ({@see JournalPostingService::void}) — so a correction to a sealed month
 * surfaces in the current one instead of silently failing.
 */
class VoidVendorBillPaymentService
{
    public function void(VendorBillPayment $payment, ?string $reason = null): VendorBillPayment
    {
        if ($payment->isVoided()) {
            return $payment; // already reversed — voiding a void is a no-op
        }

        return DB::transaction(function () use ($payment, $reason) {
            // Lock the BILL first, then re-read the payment: `recompute()` re-derives the bill's
            // paid_amount/balance/status from all of its payments, so two payments voided at once
            // must serialize on the parent or the second recompute overwrites the first's result
            // with a total it read before the first was voided. Same discipline as recordPayment(),
            // which locks the bill to cap concurrent payments at the balance.
            $bill = VendorBill::query()->lockForUpdate()->find($payment->vendor_bill_id);
            if (! $bill) {
                return $payment;
            }

            $payment = VendorBillPayment::query()->lockForUpdate()->find($payment->id);
            if (! $payment || $payment->isVoided()) {
                return $payment; // voided by a racing request while we waited for the lock
            }

            $payment->voided_at = now();
            $payment->void_reason = $reason;
            $payment->voided_by_user_id = Auth::id();
            $payment->save(); // its saved() hook recomputes the bill; the sync voids the GL entry

            // The WHY, in the immutable audit trail — `void_reason` is a column someone can later
            // edit, and the activity log is not.
            activity('vendor_bill_payment')
                ->performedOn($payment)
                ->event('voided')
                ->withProperties(array_filter([
                    'reason' => $reason,
                    'amount' => (float) $payment->amount,
                    'bill' => $bill->number,
                ]))
                ->log('vendor_bill_payment.voided');

            return $payment->refresh();
        });
    }
}
