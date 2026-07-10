<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Void / refund a captured payment — the supported reversal now that a captured payment's
 * money fields are locked (you don't edit the receipt, you refund it).
 *
 * Sets status = 'refunded': the Payment `saved` hook recomputes every invoice this payment
 * was allocated to (recomputeTotals sums only CAPTURED payments, so the AR re-opens), and the
 * ledger leg (Dr Bank / Cr AR) is voided by the real-time sync / sweep (the payment
 * journalizer returns no effect for a non-captured payment). The allocation pivot stays as a
 * historical record of what the refunded receipt had covered.
 */
class VoidPaymentService
{
    public function void(Payment $payment, ?string $reason = null): Payment
    {
        if (in_array($payment->status, ['refunded', 'failed'], true)) {
            return $payment; // already reversed
        }
        if ($payment->status !== 'captured') {
            throw new \DomainException('Only a captured payment can be voided / refunded.');
        }

        return DB::transaction(function () use ($payment, $reason) {
            if ($reason) {
                $payment->notes = trim(($payment->notes ? $payment->notes."\n" : '').'[VOID] '.$reason);
            }
            $payment->status = 'refunded';
            $payment->save(); // saved hook re-opens the allocated invoices' AR; sync voids the GL leg

            // Record the WHY in the immutable audit trail (notes is a mutable, editable field).
            activity()
                ->performedOn($payment)
                ->event('voided')
                ->withProperties(array_filter(['reason' => $reason]))
                ->log('Payment voided / refunded');

            return $payment->refresh();
        });
    }
}
