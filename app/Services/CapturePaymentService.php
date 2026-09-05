<?php

namespace App\Services;

use App\Models\Payment;
use App\Support\PostingDate;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Mark an initiated payment as captured — money that really arrived, as an ACT (SW-240).
 *
 * `initiated` → `captured` is the transition that POSTS CASH TO THE GL (`PaymentJournalizer` posts
 * on the received set, and `Invoice::recomputeTotals()` starts counting the receipt the moment it
 * lands there), and until now it rode on the form's status Select: a bare dropdown, no
 * confirmation, no named act — while every other way a payment's state moves goes through one
 * (`VoidPaymentService`, the Paymob callback, the PDC clearing).
 *
 * **The audience is real but rare, and that is the point of it being an act.** A payment is born
 * `initiated` only by a gateway session (`PaymobPaymentInitiator`) or a deliberate create-form
 * choice; the ordinary panel receipt is born `captured`. So capturing by hand means *"the gateway
 * session died mid-flight, and I have confirmed against the bank that the money genuinely
 * arrived"* — a decision worth a confirmation sentence, not a field save.
 *
 * **This service carries the SAME guards as the two capture doors beside it, and the first cut did
 * not — the adversarial review's one FATAL.** An initiated allocation is invisible to every
 * settlement sum (`RECEIVED_STATUSES` filters it out of `recomputeTotals()` and the over-allocation
 * guard alike), so in the days between initiation and this act the invoice can be settled by a
 * credit note, written off, or voided — and a bare status flip then relieves AR a SECOND time,
 * burying the excess as negative AR: the exact four-channel invariant CLAUDE.md records as having
 * happened once already, and the same checked-at-lodging-never-at-clearing shape as the PDC bug.
 * The Paymob callback answers it by CLAMPING (`refitAllocationsToBalance()` — the card money is
 * already taken, so it accepts and re-fits); an operator act REFUSES instead, because here the
 * money is not in anyone's hands yet and a refusal the operator reads beats an allocation silently
 * shrunk behind a success toast.
 */
class CapturePaymentService
{
    public function capture(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            // THE INVOICES FIRST, THEN THE PAYMENT — the canonical order (SW-009e), for the reason
            // `VoidPaymentService` states in full: the over-allocation guard locks
            // invoice→payments and cannot be reordered, so every payment-side writer must take
            // the invoices first or a capture racing an allocation edit deadlocks on MySQL.
            Payment::lockInvoicesThenSelf($payment->getKey());

            $locked = Payment::query()->lockForUpdate()->find($payment->getKey());

            // Re-checked UNDER the lock, not carried in from the pre-mount read: the Paymob
            // callback can commit `failed` (a decline) or `captured` (a late delivery) between
            // this act's mount and its click, and a check-then-act on the stale row would write
            // `captured` straight over either.
            if (! $locked || $locked->status !== 'initiated') {
                throw new DomainException(__('admin.errors.capture_not_initiated'));
            }

            PostingDate::assertOpen($locked->payment_date, __('admin.fields.payment_date'));

            // QUIETLY first, then the guards, then a clean save to fire the hooks — three steps
            // where one looks like it should do, and each is load-bearing:
            //
            //   • the over-allocation guard sums allocations of RECEIVED payments, so run while
            //     still `initiated` it cannot see THIS payment's own allocation — a fully
            //     credit-settled invoice passes and the capture over-settles it, which is the
            //     precise scenario being guarded;
            //   • run after a hook-firing save instead, the guard's refusal rolls the flip back —
            //     but `Payment::saved` has already sent `PaymentReceivedNotification` to the
            //     TENANT, synchronously, for a capture that never happened;
            //   • so: the quiet save makes the flip visible to the guards' locking reads, the
            //     guards run with nothing fired yet, and the clean `save()` afterwards fires
            //     `saving`/`saved` once — `recomputeAllocatedInvoices()`, the receipt
            //     notification, and the ledger sync — exactly once, only on success.
            $locked->status = 'captured';
            $locked->saveQuietly();

            $allocatedInvoiceIds = $locked->invoices()->pluck('invoices.id')->all();

            // Refuses a relieved invoice (written off, voided, credited — `InvoiceSettlement`
            // re-asked at capture time, not only at initiation) AND an allocation that no longer
            // fits net of write-offs and the other three channels, all under locking reads.
            $locked->assertInvoicesNotOverAllocated($allocatedInvoiceIds);

            // …and the other direction, as the Edit page's own guard block explains: a receipt
            // whose surplus was already drawn down as tenant credit has less than its face value
            // left to give.
            $locked->assertCreditNotOverdrawn();

            $locked->save();

            return $locked->refresh();
        });
    }
}
