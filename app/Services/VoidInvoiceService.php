<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * Void (cancel) an issued invoice — the supported correction path now that a finalized
 * invoice's money fields are locked (you don't edit it, you void it and re-issue).
 *
 * Sets status = 'cancelled': the Invoice `updated` hook returns any applied credit to the
 * tenant (an offsetting credit note for credit notes; soft-deleting the applications for on-account
 * tenant credit), recomputeTotals() zeros the balance (it left the books), and the ledger entry is
 * voided by the real-time sync / sweep (the invoice journalizer returns no effect for a cancelled
 * invoice). Only captured CASH blocks the void — applied non-cash credit is reversed automatically,
 * so refund a captured payment first (VoidPaymentService), then void.
 */
class VoidInvoiceService
{
    public function void(Invoice $invoice, ?string $reason = null): Invoice
    {
        if (in_array($invoice->status, ['cancelled', 'credited', 'written_off'], true)) {
            return $invoice; // already terminal — nothing to do
        }
        if ($invoice->status === 'draft') {
            throw new \DomainException(__('admin.refusals.invoice_void_draft'));
        }

        // A tax invoice already FILED with the Egyptian Tax Authority (eta_status = valid) can't be
        // reversed by an internal void — that would diverge the books from what ETA holds. It must
        // be cancelled at ETA / offset by a credit note through the compliant flow.
        if ($invoice->eta_status === 'valid') {
            throw new \DomainException(__('admin.refusals.invoice_void_eta_filed'));
        }

        // paid_amount = captured cash + reversible non-cash credit (notes + applied tenant credit);
        // the credit halves reverse on cancel, but captured CASH must be refunded first (else it
        // strands on a void invoice). capturedCashPaid() nets out both credit kinds.
        if ($invoice->capturedCashPaid() > 0) {
            throw new \DomainException(__('admin.refusals.invoice_void_has_cash'));
        }

        return DB::transaction(function () use ($invoice, $reason) {
            // Lock + re-read INSIDE the txn so two concurrent voids can't BOTH fire the
            // applied-credit reversal (the updated hook reads credit_applied_amount via a
            // non-locking ->fresh(), so a stale REPEATABLE-READ snapshot could issue a
            // second offsetting credit note = double refund). Mirrors the lock-safe
            // CreditNoteService::applyToInvoice. The blocked second void then re-reads the
            // committed 'cancelled' state and no-ops.
            $invoice = Invoice::query()->lockForUpdate()->find($invoice->id);
            if (! $invoice || in_array($invoice->status, ['cancelled', 'credited', 'written_off'], true)) {
                return $invoice; // already voided by a racing request — nothing to do
            }
            // Re-check the terminal/blocking guards under the lock (state may have moved).
            if ($invoice->eta_status === 'valid') {
                throw new \DomainException(__('admin.refusals.invoice_void_eta_filed'));
            }
            if ($invoice->capturedCashPaid() > 0) {
                throw new \DomainException(__('admin.refusals.invoice_void_has_cash'));
            }

            if ($reason) {
                $invoice->notes = trim(($invoice->notes ? $invoice->notes."\n" : '').'[VOID] '.$reason);
            }
            $invoice->status = 'cancelled';
            $invoice->save();          // activity-logged; updated hook reverses any applied credit
            $invoice->recomputeTotals(); // zeros the balance via the cancelled branch (source of truth)

            // Record the WHY in the immutable audit trail (notes is a mutable, editable field).
            activity('invoice')
                ->performedOn($invoice)
                ->event('voided')
                ->withProperties(array_filter(['reason' => $reason]))
                ->log('invoice.voided');

            return $invoice->refresh();
        });
    }
}
