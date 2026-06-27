<?php

namespace App\Http\Controllers\Paymob;

use App\Models\Payment;
use App\Services\Paymob\PaymobClient;
use App\Services\Paymob\PaymobPaymentInitiator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Two roles:
 *
 *   processed() — Paymob's server-to-server "transaction processed" callback.
 *                 HMAC-verified. Flips the matching 'initiated' Payment to
 *                 captured / failed. Idempotent.
 *
 *   returned()  — Browser redirect after the iframe. We trust nothing here,
 *                 we just bounce the tenant back to the portal invoice.
 *
 * Routes (registered in routes/web.php):
 *   POST  /paymob/callback   → processed
 *   GET   /paymob/return     → returned
 */
class CallbackController
{
    public function __construct(protected PaymobClient $client) {}

    public function processed(Request $request): JsonResponse
    {
        $signature = (string) $request->query('hmac', '');
        $payload = $request->all();

        if (! $this->client->verifyHmac($payload, $signature)) {
            // Log the payload SHAPE (keys only — never values/PII) so a non-standard
            // callback can be diagnosed. Paymob fires more than just the charge
            // callback to this URL (e.g. ones with a null order id); those legitimately
            // fail HMAC and are harmless, but we want to see what arrived.
            Log::warning('Paymob callback rejected: bad HMAC', [
                'has_signature' => $signature !== '',
                'has_obj' => array_key_exists('obj', $payload),
                'order_id' => data_get($payload, 'obj.order.id'),
                'txn_id' => data_get($payload, 'obj.id'),
                'payload_keys' => array_keys($payload),
                'obj_keys' => is_array($payload['obj'] ?? null) ? array_keys($payload['obj']) : null,
            ]);

            return response()->json(['ok' => false, 'error' => 'invalid_hmac'], 401);
        }

        $obj = $payload['obj'] ?? [];
        $orderId = (int) data_get($obj, 'order.id', 0);
        $txnId = (int) data_get($obj, 'id', 0);

        if (! $orderId) {
            return response()->json(['ok' => false, 'error' => 'missing_order_id'], 422);
        }

        $payment = Payment::where('gateway', 'paymob')
            ->where('gateway_transaction_id', PaymobPaymentInitiator::orderRef($orderId))
            ->first();

        if (! $payment) {
            // Could be a duplicate retry after we've already promoted the
            // gateway_transaction_id, or an unknown order. Either way we 200
            // so Paymob doesn't retry indefinitely.
            Log::info('Paymob callback for unknown order — already processed?', [
                'order_id' => $orderId,
                'txn_id' => $txnId,
            ]);

            return response()->json(['ok' => true, 'skipped' => 'unknown_order']);
        }

        if (in_array($payment->status, ['captured', 'failed', 'refunded'], true)) {
            return response()->json(['ok' => true, 'skipped' => 'already_processed']);
        }

        $success = (bool) data_get($obj, 'success', false);
        $voided = (bool) data_get($obj, 'is_voided', false);
        $isCapture = $success && ! $voided;

        DB::transaction(function () use ($payment, $obj, $txnId, $orderId, $isCapture) {
            $payment->gateway_transaction_id = "paymob:txn:{$txnId}:order:{$orderId}";
            $payment->gateway_response = $obj;
            $payment->status = $isCapture ? 'captured' : 'failed';
            $payment->save();
            // Payment::saved hook recomputes invoice totals + fires
            // PaymentReceivedNotification on the captured transition.
        });

        return response()->json([
            'ok' => true,
            'payment_id' => $payment->id,
            'status' => $payment->status,
        ]);
    }

    public function returned(Request $request): RedirectResponse
    {
        // Paymob appends ?success=true|false&id=<txn>&order=<order_id>… to
        // the merchant return URL. We only use it for routing/UX — the
        // server-to-server processed() callback is the source of truth.
        $success = $request->query('success') === 'true';
        $orderId = (int) $request->query('order', 0);

        // A payment-link payment returns to the PUBLIC status page; in-app
        // (mobile / portal / admin) payments return to the authenticated portal.
        if ($orderId) {
            $payment = Payment::where('gateway', 'paymob')
                ->where(fn ($q) => $q
                    ->where('gateway_transaction_id', PaymobPaymentInitiator::orderRef($orderId))
                    ->orWhere('gateway_transaction_id', 'like', '%:order:'.$orderId))
                ->latest('id')
                ->first();

            if ($payment?->channel === Payment::CHANNEL_LINK) {
                $token = $payment->invoices()->first()?->payment_link_token;
                if ($token) {
                    return redirect()->route('pay.status', ['token' => $token]);
                }
            }
        }

        return redirect()->to('/portal/invoices')->with(
            $success ? 'status' : 'error',
            $success
                ? __('admin.notifications.payment_return_success')
                : __('admin.notifications.payment_return_failed'),
        );
    }
}
