<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class CreditNoteService
{
    /**
     * Issue a draft credit note (status -> issued). Sets balance = total - applied_amount
     * and stamps issued_at via the timestamps. Idempotent on issued status.
     */
    public function issue(CreditNote $note): CreditNote
    {
        if ($note->status === 'issued' || $note->status === 'applied') {
            return $note;
        }

        return DB::transaction(function () use ($note) {
            $note->balance = (float) $note->total - (float) $note->applied_amount;
            $note->status = $note->balance > 0 ? 'issued' : 'applied';
            $note->save();

            return $note->refresh();
        });
    }

    /**
     * Apply (some of) a credit note's remaining balance to one invoice.
     * Updates both sides atomically. Caps the application at min(invoice.balance, note.balance).
     *
     * Returns the actual amount applied. 0 means nothing applied (note voided, no balance, etc).
     */
    public function applyToInvoice(CreditNote $note, Invoice $invoice, ?float $requestedAmount = null): float
    {
        return DB::transaction(function () use ($note, $invoice, $requestedAmount) {
            // Lock BOTH rows and re-read their state INSIDE the txn. Two concurrent
            // applies would otherwise each observe the same pre-state and both
            // commit their full amount — over-applying the credit (note balance
            // goes negative; invoice credit_applied exceeds total). Mirrors the
            // payment path's lock-safe over-allocation guard.
            $note = CreditNote::query()->lockForUpdate()->find($note->id);
            $invoice = Invoice::query()->lockForUpdate()->find($invoice->id);

            // hasBalance() = balance > 0 AND status in [issued, applied] — blocks
            // draft / void / fully-applied notes (re-checked under the lock).
            if (! $note || ! $invoice || ! $note->hasBalance()) {
                return 0.0;
            }

            $available = (float) $note->balance;
            $owed = (float) $invoice->balance;

            if ($available <= 0 || $owed <= 0) {
                return 0.0;
            }

            // Only apply to a live, payable invoice — applying to a cancelled /
            // credited / disputed / draft / paid invoice would consume the credit's
            // balance against a row that isn't collecting, silently leaking it.
            if (! in_array($invoice->status, ['issued', 'partially_paid', 'overdue'], true)) {
                return 0.0;
            }

            $amount = $requestedAmount === null
                ? min($available, $owed)
                : min($available, $owed, (float) $requestedAmount);

            if ($amount <= 0) {
                return 0.0;
            }

            // Adjust the credit note side (capped at $available above → never negative).
            $note->applied_amount = (float) $note->applied_amount + $amount;
            $note->balance = (float) $note->total - (float) $note->applied_amount;
            $note->status = $note->balance > 0 ? 'issued' : 'applied';
            $note->applied_at = $note->applied_at ?? now();
            $note->save();

            // Record the applied credit durably (credit_applied_amount) so
            // Invoice::recomputeTotals — which otherwise sums only the payments
            // pivot — folds it into paid_amount/balance/status. This keeps a
            // later payment recompute from erasing the credit.
            $invoice->credit_applied_amount = (float) $invoice->credit_applied_amount + $amount;
            $invoice->recomputeTotals();

            return $amount;
        });
    }

    /**
     * Void a credit note. Cannot void an applied one without reversing the application —
     * for v1 we refuse to void if applied_amount > 0. Caller can issue an offsetting note
     * if needed.
     */
    public function void(CreditNote $note, ?string $reason = null): CreditNote
    {
        if ($note->status === 'void') {
            return $note;
        }

        if ((float) $note->applied_amount > 0) {
            throw new \DomainException('Cannot void a credit note that has already been applied. Issue an offsetting note instead.');
        }

        return DB::transaction(function () use ($note, $reason) {
            $note->status = 'void';
            $note->voided_at = now();
            if ($reason) {
                $note->notes = trim(($note->notes ? $note->notes . "\n" : '') . "Voided: {$reason}");
            }
            $note->balance = 0;
            $note->save();

            return $note->refresh();
        });
    }
}
