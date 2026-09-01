<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Void / refund a captured payment — the supported reversal now that a captured payment's
 * money fields are locked (you don't edit the receipt, you refund it).
 *
 * Sets status = 'voided': the Payment `saved` hook recomputes every invoice this payment
 * was allocated to (recomputeTotals sums only CAPTURED payments, so the AR re-opens), and the
 * ledger leg (Dr Bank / Cr AR) is voided by the real-time sync / sweep (the payment
 * journalizer returns no effect for a non-captured payment). The allocation pivot stays as a
 * historical record of what the refunded receipt had covered.
 */
class VoidPaymentService
{
    public function void(Payment $payment, ?string $reason = null): Payment
    {
        if ($payment->isReversed()) {
            return $payment; // already reversed
        }
        if (! $payment->isReceived()) {
            throw new \DomainException(__('admin.refusals.payment_void_state'));
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
                // The invoices' own column. Through the lease chain this produced an EMPTY array
                // for a receipt allocated only to owner invoices, and the empty array fell through
                // to `creditBalance(null)` — the GLOBAL balance that the comment four lines above
                // explicitly forbids. A fail-open on a refund guard.
                //
                // ATOMIC with `Tenant::creditBalance()`, which scopes the same way: migrating only
                // one of the two turns this fail-open into a false REFUSAL of every owner-invoice
                // refund that carries an unallocated surplus.
                $assetIds = $payment->invoices()->pluck('invoices.asset_id')
                    ->filter()->unique()->values()->all();

                // A receipt with NO allocations at all still has a property, and it is the one this
                // guard must ask about. `[]` used to fall through to `creditBalance(null)` — the
                // GLOBAL balance the comment above explicitly forbids — so an unrelated credit at
                // another mall let the void through on a receipt whose own surplus was already
                // spent here: the money is refunded AND still settling AR, and the tenant's credit
                // goes negative for the difference.
                //
                // A zero-allocation receipt is the ordinary case, not an exotic one: a cleared
                // SERIES cheque names no invoice, which is the Egyptian norm. `originatingAssetId()`
                // is where its property lives — the same fact `Tenant::creditBalance()` already
                // reaches for through `clearedCheque` when it attributes that credit, so asking it
                // here keeps the two halves of one question on one answer.
                if ($assetIds === [] && ($originating = $payment->originatingAssetId()) !== null) {
                    $assetIds = [$originating];
                }

                // Still `null` only when the receipt has no allocations AND no cheque to name a
                // property — nothing in the data says which mall it belongs to, and a global
                // balance is then the honest answer rather than a fabricated scope.
                $available = (float) $tenant->creditBalance($assetIds !== [] ? $assetIds : null);
                if (round($available - $remainder, 2) < -0.005) {
                    throw new \DomainException(__('admin.payment.refund_blocked_credit_applied'));
                }
            }

            if ($reason) {
                $payment->notes = trim(($payment->notes ? $payment->notes."\n" : '').'[VOID] '.$reason);
            }
            $payment->status = 'voided';
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
