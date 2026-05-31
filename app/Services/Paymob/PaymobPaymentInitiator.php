<?php

namespace App\Services\Paymob;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Glue between Filament Pay-Now actions and PaymobClient. Creates an
 * 'initiated' Payment + allocates it to the invoice (which stays a no-op
 * until the callback flips status to 'captured' — see Invoice::recomputeTotals
 * which only counts captured allocations) and returns the iframe URL the
 * tenant should be redirected to.
 *
 * Audit M11 F-42 / D-33.
 */
class PaymobPaymentInitiator
{
    public function __construct(protected PaymobClient $client) {}

    /**
     * Returns the Paymob iframe URL for the tenant to be redirected to.
     * Creates an 'initiated' Payment row keyed by the Paymob order_id so
     * the callback handler can find it back without storing extra state.
     */
    public function start(Invoice $invoice): string
    {
        $session = $this->client->buildPaymentSession($invoice);

        DB::transaction(function () use ($invoice, $session) {
            $payment = Payment::create([
                'tenant_id' => $invoice->tenant_id,
                'amount' => $invoice->balance,
                'currency' => $invoice->currency ?? 'EGP',
                'method' => 'card',
                'status' => 'initiated',
                'payment_date' => now(),
                'gateway' => 'paymob',
                'gateway_transaction_id' => self::orderRef($session['order_id']),
                'gateway_response' => [
                    'order_id' => $session['order_id'],
                    'iframe_url' => $session['iframe_url'],
                ],
            ]);

            // Allocate the full invoice balance upfront. Invoice::recomputeTotals
            // filters by payments.status = 'captured', so this allocation has
            // zero effect on the AR balance until the callback marks the
            // Payment captured.
            $payment->invoices()->attach($invoice->id, [
                'allocated_amount' => round((float) $invoice->balance, 2),
            ]);
        });

        return $session['iframe_url'];
    }

    /**
     * Convention for the gateway_transaction_id we stash on initiated rows so
     * the callback can recover the Payment by Paymob's order id. After capture
     * the controller appends the transaction id (see CallbackController).
     */
    public static function orderRef(int $orderId): string
    {
        return "paymob:order:{$orderId}";
    }
}
