<?php

namespace App\Services\Paymob;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Glue between callers (Filament Pay-Now actions + the mobile API session
 * endpoint) and PaymobClient. Creates an 'initiated' Payment + allocates it
 * to the invoice (a no-op until the callback flips status to 'captured' —
 * see Invoice::recomputeTotals which only counts captured allocations) and
 * returns the session payload the caller can hand to the iframe / Paymob
 * SDK.
 *
 * Idempotent: if a recent 'initiated' Payment already exists for this
 * invoice, its stored session is reused instead of burning a fresh Paymob
 * order on every tap. The reuse window is shorter than the Paymob token
 * expiration (3600s) by a safety margin so we never serve a token that
 * will expire mid-checkout.
 *
 * Audit M11 F-42 / D-33.
 */
class PaymobPaymentInitiator
{
    /**
     * Reuse an existing initiated session if it was created less than this
     * many seconds ago. Below Paymob's 3600s payment_token TTL by enough
     * margin that the user has time to fill the card form.
     */
    public const REUSE_WINDOW_SECONDS = 2700;

    public function __construct(protected PaymobClient $client) {}

    /**
     * Returns the Paymob session for this invoice:
     *
     *   [
     *     'payment_token' => string,    // for the Paymob mobile SDK
     *     'iframe_url'    => string,    // for WebView / browser redirect
     *     'order_id'      => int,       // Paymob's order id
     *     'payment_id'    => int,       // our Payment row id (poll target)
     *     'expires_at'    => CarbonImmutable, // session-token expiration
     *     'reused'        => bool,      // true if returned from cache
     *   ]
     *
     * The 'initiated' Payment row is keyed by Paymob's order_id so the S2S
     * callback can recover it without extra state.
     *
     * @return array{payment_token:string,iframe_url:string,order_id:int,payment_id:int,expires_at:\Carbon\CarbonImmutable,reused:bool}
     */
    public function start(Invoice $invoice): array
    {
        if ($reused = $this->findReusableSession($invoice)) {
            return $reused;
        }

        $session = $this->client->buildPaymentSession($invoice);

        return DB::transaction(function () use ($invoice, $session) {
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
                    'payment_token' => $session['payment_token'],
                    'iframe_url' => $session['iframe_url'],
                    'issued_at' => now()->toIso8601String(),
                ],
            ]);

            // Allocate the full invoice balance upfront. Invoice::recomputeTotals
            // filters by payments.status = 'captured', so this allocation has
            // zero effect on the AR balance until the callback marks the
            // Payment captured.
            $payment->invoices()->attach($invoice->id, [
                'allocated_amount' => round((float) $invoice->balance, 2),
            ]);

            return [
                'payment_token' => $session['payment_token'],
                'iframe_url' => $session['iframe_url'],
                'order_id' => (int) $session['order_id'],
                'payment_id' => (int) $payment->id,
                'expires_at' => Carbon::now()->addSeconds(PaymobClient::PAYMENT_TOKEN_TTL_SECONDS)->toImmutable(),
                'reused' => false,
            ];
        });
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

    /**
     * Look for an 'initiated' Payment for this invoice whose stored Paymob
     * session is still within the reuse window. Returns the same shape as
     * start() with reused=true, or null if no usable session exists.
     */
    protected function findReusableSession(Invoice $invoice): ?array
    {
        $payment = Payment::query()
            ->where('gateway', 'paymob')
            ->where('status', 'initiated')
            ->where('tenant_id', $invoice->tenant_id)
            ->whereHas('invoices', fn ($q) => $q->where('invoices.id', $invoice->id))
            ->where('created_at', '>=', now()->subSeconds(self::REUSE_WINDOW_SECONDS))
            ->latest('id')
            ->first();

        if (! $payment) {
            return null;
        }

        // Only reuse a session whose amount still matches what's owed. If a
        // credit or partial payment reduced the invoice balance since the
        // session was created, the gateway token is bound to the OLD (higher)
        // amount — reusing it would overcharge. Fall through to a fresh session.
        if (round((float) $payment->amount, 2) !== round((float) $invoice->balance, 2)) {
            return null;
        }

        $stored = (array) $payment->gateway_response;
        if (empty($stored['iframe_url']) || empty($stored['payment_token']) || empty($stored['order_id'])) {
            return null;
        }

        return [
            'payment_token' => (string) $stored['payment_token'],
            'iframe_url' => (string) $stored['iframe_url'],
            'order_id' => (int) $stored['order_id'],
            'payment_id' => (int) $payment->id,
            'expires_at' => Carbon::parse($stored['issued_at'] ?? $payment->created_at)
                ->addSeconds(PaymobClient::PAYMENT_TOKEN_TTL_SECONDS)
                ->toImmutable(),
            'reused' => true,
        ];
    }
}
