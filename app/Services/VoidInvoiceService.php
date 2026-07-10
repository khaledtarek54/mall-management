<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * Void (cancel) an issued invoice — the supported correction path now that a finalized
 * invoice's money fields are locked (you don't edit it, you void it and re-issue).
 *
 * Sets status = 'cancelled': the Invoice `updated` hook returns any applied credit to the
 * tenant (an offsetting credit note), recomputeTotals() zeros the balance (it left the
 * books), and the ledger entry is voided by the real-time sync / sweep (the invoice
 * journalizer returns no effect for a cancelled invoice). Captured CASH payments block the
 * void — refund the payment first (VoidPaymentService), then void.
 */
class VoidInvoiceService
{
    public function void(Invoice $invoice, ?string $reason = null): Invoice
    {
        if (in_array($invoice->status, ['cancelled', 'credited'], true)) {
            return $invoice; // already terminal — nothing to do
        }
        if ($invoice->status === 'draft') {
            throw new \DomainException('A draft invoice is deleted, not voided — voiding is for issued invoices.');
        }

        // A tax invoice already FILED with the Egyptian Tax Authority (eta_status = valid) can't be
        // reversed by an internal void — that would diverge the books from what ETA holds. It must
        // be cancelled at ETA / offset by a credit note through the compliant flow.
        if ($invoice->eta_status === 'valid') {
            throw new \DomainException('This invoice was filed with the Tax Authority (ETA) and cannot be voided internally — issue a credit note (and an ETA cancellation) instead.');
        }

        // paid_amount = captured payments + applied credit; the credit half is reversed on
        // cancel, but captured CASH must be refunded first (else it strands on a void invoice).
        $capturedCash = round((float) $invoice->paid_amount - (float) $invoice->credit_applied_amount, 2);
        if ($capturedCash > 0) {
            throw new \DomainException('Cannot void an invoice with captured payments — void/refund the payment first, then void the invoice.');
        }

        return DB::transaction(function () use ($invoice, $reason) {
            if ($reason) {
                $invoice->notes = trim(($invoice->notes ? $invoice->notes."\n" : '').'[VOID] '.$reason);
            }
            $invoice->status = 'cancelled';
            $invoice->save();          // activity-logged; updated hook reverses any applied credit
            $invoice->recomputeTotals(); // zeros the balance via the cancelled branch (source of truth)

            // Record the WHY in the immutable audit trail (notes is a mutable, editable field).
            activity()
                ->performedOn($invoice)
                ->event('voided')
                ->withProperties(array_filter(['reason' => $reason]))
                ->log('Invoice voided');

            return $invoice->refresh();
        });
    }
}
