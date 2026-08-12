<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Tenant;
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
        if (in_array($payment->status, ['refunded', 'failed', 'bounced'], true)) {
            return $payment; // already reversed
        }
        if (! $payment->isReceived()) {
            throw new \DomainException('Only a received payment can be voided / refunded.');
        }

        return DB::transaction(function () use ($payment, $reason) {
            // Lock + re-read inside the txn so two concurrent refunds serialize (and the
            // second no-ops), mirroring the void-invoice + apply-credit lock discipline.
            $payment = Payment::query()->lockForUpdate()->find($payment->id);
            if (! $payment || ! $payment->isReceived()) {
                return $payment; // already reversed by a racing request
            }

            // Don't refund a receipt whose UNALLOCATED surplus has already been applied to invoices as
            // tenant credit — that would refund money that was also used to settle AR, leaving the
            // tenant with a negative credit. Block it (reverse those credit applications first).
            // Scope the credit check to THIS receipt's property(ies): a global balance would let an
            // unrelated credit at another mall mask that this receipt's own surplus was already spent.
            $remainder = round(
                (float) $payment->amount - (float) $payment->invoices()->sum('invoice_payment.allocated_amount'),
                2,
            );
            $tenant = $payment->tenant;
            if ($remainder > 0.005 && $tenant instanceof Tenant) {
                $assetIds = $payment->invoices()->with('lease.unit')->get()
                    ->map(fn ($inv) => $inv->lease?->unit?->asset_id)
                    ->filter()->unique()->values()->all();
                $available = (float) $tenant->creditBalance($assetIds !== [] ? $assetIds : null);
                if (round($available - $remainder, 2) < -0.005) {
                    throw new \DomainException(__('admin.payment.refund_blocked_credit_applied'));
                }
            }

            if ($reason) {
                $payment->notes = trim(($payment->notes ? $payment->notes."\n" : '').'[VOID] '.$reason);
            }
            $payment->status = 'refunded';
            $payment->save(); // saved hook re-opens the allocated invoices' AR; sync voids the GL leg

            // Record the WHY in the immutable audit trail (notes is a mutable, editable field).
            activity('payment')
                ->performedOn($payment)
                ->event('voided')
                ->withProperties(array_filter(['reason' => $reason]))
                ->log('payment.voided');

            return $payment->refresh();
        });
    }
}
