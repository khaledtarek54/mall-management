<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PostDatedCheque;
use App\Models\User;
use App\Support\PostingDate;
use Illuminate\Support\Facades\DB;

/**
 * The post-dated-cheque lifecycle: deposit → clear (or bounce), and cancel. v1 is register-only:
 * CLEARING is the only step that touches money — it records a normal cheque Payment through the
 * existing payment flow (allocating to the linked invoice, capped at its balance) so
 * Invoice::recomputeTotals() stays the AR single source of truth. Everything else just moves the
 * cheque's status. Lock-safe + idempotent (row-lock + re-check under the lock).
 */
class PostDatedChequeService
{
    public function deposit(PostDatedCheque $cheque): PostDatedCheque
    {
        return DB::transaction(function () use ($cheque) {
            $cheque = PostDatedCheque::whereKey($cheque->id)->lockForUpdate()->firstOrFail();

            if (! in_array($cheque->status, [PostDatedCheque::STATUS_HELD, PostDatedCheque::STATUS_BOUNCED], true)) {
                throw new \DomainException('Only a held (or re-presented, bounced) cheque can be deposited.');
            }

            $cheque->update(['status' => PostDatedCheque::STATUS_DEPOSITED]);

            return $cheque;
        });
    }

    /** Clear a cheque: record a captured cheque Payment against its invoice, then flip to cleared. */
    public function clear(PostDatedCheque $cheque, User $actor, ?string $clearedOn = null): PostDatedCheque
    {
        return DB::transaction(function () use ($cheque, $actor, $clearedOn) {
            $cheque = PostDatedCheque::whereKey($cheque->id)->lockForUpdate()->firstOrFail();

            if (! in_array($cheque->status, [PostDatedCheque::STATUS_HELD, PostDatedCheque::STATUS_DEPOSITED], true)) {
                throw new \DomainException('Only a held or deposited cheque can be cleared.');
            }

            // Money moved: not future, and the period must be open (the Payment will post to the GL).
            $date = PostingDate::assertNotFuture($clearedOn ?? now()->toDateString(), 'cleared_on');

            $payment = Payment::create([
                'reference' => Payment::generateReference(),
                'tenant_id' => $cheque->tenant_id,
                'amount' => round((float) $cheque->amount, 2),
                'currency' => $cheque->currency,
                'method' => 'cheque',
                'status' => 'captured',
                'payment_date' => $date->toDateString(),
                'cheque_number' => $cheque->cheque_number,
                'received_by' => $actor->id,
                'notes' => "Cleared post-dated cheque {$cheque->reference}",
            ]);

            // Allocate to the linked invoice, capped at its balance (the surplus stays as an
            // on-account credit rather than over-paying the invoice).
            if ($cheque->invoice_id) {
                $invoice = Invoice::find($cheque->invoice_id);
                $allocate = $invoice ? min(round((float) $cheque->amount, 2), round((float) $invoice->balance, 2)) : 0.0;
                if ($allocate > 0) {
                    $payment->invoices()->sync([$cheque->invoice_id => ['allocated_amount' => $allocate]]);
                    $payment->recomputeAllocatedInvoices();
                }
            }

            $cheque->update([
                'status' => PostDatedCheque::STATUS_CLEARED,
                'cleared_payment_id' => $payment->id,
            ]);

            return $cheque;
        });
    }

    public function bounce(PostDatedCheque $cheque): PostDatedCheque
    {
        return DB::transaction(function () use ($cheque) {
            $cheque = PostDatedCheque::whereKey($cheque->id)->lockForUpdate()->firstOrFail();

            if (! in_array($cheque->status, [PostDatedCheque::STATUS_HELD, PostDatedCheque::STATUS_DEPOSITED], true)) {
                throw new \DomainException('Only a held or deposited cheque can bounce.');
            }

            // No Payment was made before clearing, so a bounce reverses nothing — the tenant's
            // invoice was never reduced. The cheque can be re-presented (deposited) or cancelled.
            $cheque->update(['status' => PostDatedCheque::STATUS_BOUNCED]);

            return $cheque;
        });
    }

    public function cancel(PostDatedCheque $cheque): PostDatedCheque
    {
        return DB::transaction(function () use ($cheque) {
            $cheque = PostDatedCheque::whereKey($cheque->id)->lockForUpdate()->firstOrFail();

            if ($cheque->status === PostDatedCheque::STATUS_CLEARED) {
                throw new \DomainException('A cleared cheque cannot be cancelled (void its payment instead).');
            }
            if ($cheque->status === PostDatedCheque::STATUS_CANCELLED) {
                return $cheque;
            }

            $cheque->update(['status' => PostDatedCheque::STATUS_CANCELLED]);

            return $cheque;
        });
    }
}
