<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\ReversalReason;
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
 *
 * **The refusals below name the invoice, because this service is mostly called from somewhere
 * else.** `void()` is dispatched from the invoice's own Edit page — where the operator can see
 * which document they are looking at — but also from
 * `PercentageRentCalculationService::reverseOverage()` (driven from the sales-declaration screen)
 * and from `CamReconciliationService::voidAllocation()`, which voids up to TWO invoices plus a
 * credit note in one loop from the CAM allocation tab. Both of those callers' own docblocks name a
 * refusal from here as the expected outcome, so it is the common case there, not an edge.
 *
 * A `DomainException` renders as a toast (`bootstrap/app.php`), and the toast said *"This invoice
 * carries a write-off"* with nothing to say WHICH invoice — and on the CAM path no way to tell
 * which of the two. Measured on the cascade: `voidLocked()` over a locked declaration whose 2,500
 * overage invoice carried a 100 write-off refused with a sentence naming no document, and the
 * document it meant appears nowhere on the screen the operator is standing on. The escape the
 * message names — *reverse the write-off first* — is only actionable once they can find it.
 * The sibling write-off refusals one file away (`write_off_not_live`, `write_off_already_full`, …)
 * have named `Invoice :number` since they were written (SW-231).
 *
 * The `eta_status` branch is left as it stands: module 16 is `Modules::FROZEN`, so nothing on a
 * current install sets that column to `valid`.
 */
class VoidInvoiceService
{
    public function void(Invoice $invoice, ?string $reason = null): Invoice
    {
        if (in_array($invoice->status, ['cancelled', 'credited', 'written_off'], true)) {
            return $invoice; // already terminal — nothing to do
        }
        // **A DRAFT is cancelled here rather than refused, because the door this used to name does
        // not exist.** The refusal read *"A draft invoice is deleted, not voided"* — and `Invoice`
        // is `#[NeverDeletable]`, so `canDelete()`/`canDeleteAny()` are false even for super_admin,
        // there is no `DeleteAction` on the resource, and the bulk one is hidden panel-wide. The
        // form removes `cancelled` from its options too. So an abandoned draft had NO way out at
        // all, and that is not cosmetic: `MonthlyBillingService`'s already-billed probe counted it,
        // so the lease-month it covered could never be billed again — the silent lost revenue the
        // probe's own comment describes at length for the cancelled case.
        //
        // Harmless until 2026-09-02, because a panel draft could not survive its first line
        // (`recomputeTotals()` promoted it). Freezing that (SW-215) made the state persist, so it
        // needed an exit.
        //
        // Nothing was ever posted, so this is not a void: no reversal, no credit note, no number
        // burnt — just a document that never became one. It falls straight through to the terminal
        // check above on a second call.
        if ($invoice->status === 'draft') {
            $invoice->update(['status' => 'cancelled']);

            ReversalReason::record($invoice, 'cancelled', $reason);

            return $invoice->refresh();
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
            throw new \DomainException(__('admin.refusals.invoice_void_has_cash', [
                'number' => $invoice->number,
            ]));
        }

        // **A standing WRITE-OFF blocks the void, for the same reason captured cash does.** A
        // write-off is an accounting ACT with its own reversal, and it posts
        // `Dr bad_debt_expense / Cr accounts_receivable` against a row this void does not touch. So
        // voiding on top of it left the loss standing against a document that no longer exists:
        // measured on a 10,000 invoice with 4,000 written off, the books came out **AR −4,000** —
        // the invoice's own debit reversed, with the write-off's credit standing against nothing —
        // and 4,000 of bad-debt expense. Negative receivables for one debt, and a loss recognised on
        // money that was never owed (SW-023).
        //
        // Counted over `JournalEntry::REPORTABLE_STATUSES` (`posted` + `void`), which is what every
        // financial read uses: voiding posts a sign-flipped reversal and marks the original `void`,
        // so summing `posted` alone reads the reversal without the thing it reverses.
        //
        // Refused rather than cascaded, which is this codebase's rule for money records: correct
        // them through their OWN workflow, so an auditor can follow what happened. `Reverse
        // write-off` is a real button (`WriteOffInvoiceService::reverse()`, in the *corrections*
        // header group, on the same `invoices.void` right and with no status bar of its own), and
        // reversing it first
        // leaves a trail that says the debt was re-opened and then the document withdrawn — which is
        // what actually happened. Cascading would silently undo an act somebody took deliberately.
        //
        // A FULLY written-off invoice never reaches here: `written_off` is in the terminal list
        // above, so this bites only on the partial case, which is the one that moves money.
        if ($invoice->writeOffs()->exists()) {
            throw new \DomainException(__('admin.refusals.invoice_void_has_write_off', [
                'number' => $invoice->number,
            ]));
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
            // Named for the same reason as the pre-lock twin above. This branch fires only when the
            // state moves between that check and the lock, so a single-threaded test cannot reach it
            // and it is NOT mutation-proved — it is here so a racing void does not produce the one
            // anonymous toast left in the method.
            if ($invoice->capturedCashPaid() > 0) {
                throw new \DomainException(__('admin.refusals.invoice_void_has_cash', [
                    'number' => $invoice->number,
                ]));
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
