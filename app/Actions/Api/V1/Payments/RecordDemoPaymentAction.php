<?php

namespace App\Actions\Api\V1\Payments;

use App\Models\Invoice;
use App\Models\Payment;
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
    public function handle(Invoice $invoice): Payment
    {
        return DB::transaction(function () use ($invoice) {
            $amount = round((float) $invoice->balance, 2);

            $payment = Payment::create([
                'tenant_id' => $invoice->tenant_id,
                'amount' => $amount,
                'currency' => $invoice->currency ?? 'EGP',
                'method' => 'card',
                'status' => 'initiated',
                'payment_date' => now(),
                'gateway' => 'demo',
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

            return $payment->load('invoices');
        });
    }
}
