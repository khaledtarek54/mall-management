<?php

namespace App\Actions\Api\V1\Payments;

use App\Models\Invoice;
use App\Models\Payment;
use App\Support\InvoiceSettlement;
use Illuminate\Support\Facades\DB;

/**
 * Demo-only: simulate a successful gateway payment for an invoice.
 *
 * Mirrors the real Paymob path (PaymobPaymentInitiator + the S2S callback)
 * exactly — create an `initiated` Payment, allocate the full balance, then
 * flip it to `captured`. That status transition runs Payment::saved, which
 * recomputes the invoice (→ paid) and fires PaymentReceivedNotification.
 * Reusing the same path keeps balances, AR ageing, and notifications byte-for-
 * byte identical to a real capture; the only difference is no gateway call.
 *
 * The caller gates this to PAYMOB_ENABLED=false so it can never run once a
 * live gateway is wired up.
 */
class RecordDemoPaymentAction
{
    /**
     * @param  string|null  $channel  Which surface raised it (`Payment::CHANNEL_*`).
     *
     * The public pay page MUST pass `CHANNEL_LINK`: `PaymentLinkController::status()` finds the
     * payment behind a link by `where('channel', CHANNEL_LINK)`, so a null-channel demo capture
     * leaves the status page unable to find the payment it just took — it reads the invoice
     * balance instead and reports a paid invoice for 0.00. Null stays the default so the portal
     * and mobile callers keep the channel they already record (none).
     */
    public function handle(Invoice $invoice, ?string $channel = null): Payment
    {
        return DB::transaction(function () use ($invoice, $channel) {
            // Lock + re-check the balance INSIDE the txn so two concurrent
            // pay-demo requests can't both read a positive balance and
            // over-capture the invoice (the second serialises on the lock and
            // sees balance 0).
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            // `payableAmount()`, not the raw balance, on BOTH the guard and the amount. A partial
            // write-off deliberately leaves `balance` standing — that is what makes a write-off
            // visible on the document — so capturing it takes money the operator already forgave,
            // driving AR negative for the debt and leaving bad-debt expense standing against cash
            // that arrived. It also closes the statuses a balance test cannot see: `draft` most of
            // all, since the public /pay route has no login in front of it.
            // The LOCKING twin, because this is a guard and not a render: a plain read behind the
            // lock above is answered from the snapshot taken before we waited, which is what both
            // of `Payment`'s over-allocation guards take this same lock to avoid.
            $amount = InvoiceSettlement::accepts($invoice) ? $invoice->collectableBalanceForUpdate() : 0.0;

            abort_if($amount <= 0, 422, __('admin.notifications.pay_now_failed_body'));

            $payment = Payment::create([
                'tenant_id' => $invoice->tenant_id,
                'amount' => $amount,
                'currency' => $invoice->currency ?? 'EGP',
                'method' => 'card',
                'status' => 'initiated',
                'payment_date' => now(),
                'gateway' => 'demo',
                'channel' => $channel,
                'gateway_transaction_id' => uniqid('demo:invoice:'.$invoice->id.':', true),
                'gateway_response' => [
                    'demo' => true,
                    'note' => 'Simulated successful payment (Paymob disabled).',
                ],
            ]);

            $payment->invoices()->attach($invoice->id, ['allocated_amount' => $amount]);

            // Capture transition → Payment::saved recomputes the invoice and
            // notifies the tenant, exactly as the real callback would.
            $payment->status = 'captured';
            $payment->save();

            // Lock-safe backstop: roll back if this capture pushed the invoice
            // past its total (a parallel real/demo capture racing this one).
            $payment->assertInvoicesNotOverAllocated([$invoice->id]);

            return $payment->load('invoices');
        });
    }
}
