<?php

namespace App\Http\Controllers\Paymob;

use App\Models\Payment;
use App\Services\Paymob\PaymobClient;
use App\Services\Paymob\PaymobPaymentInitiator;
use App\Support\OpsLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            OpsLog::warning('Paymob callback rejected: bad HMAC', [
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

        // Match on the ORDER, which never changes, and not on the exact string this controller is
        // about to overwrite. `gateway_transaction_id` starts life as `paymob:order:{id}` and the
        // first callback promotes it to `paymob:txn:{txn}:order:{id}` — so every LATER callback for
        // the same order looked up a key that no longer existed and was filed as "unknown order".
        //
        // A Paymob order carries MANY transactions: a shopper whose card is declined and who presses
        // "try again" on the same hosted page produces a second transaction under the same order. If
        // that retry succeeds, the money is taken and this controller was dropping the news of it.
        // Observed on 2026-08-17 — order 589424727, declined as txn 229844534, then txn 803955240
        // one minute later, unmatched and discarded with no record of whether it had succeeded.
        //
        // The suffix match keeps rows that were ALREADY promoted reachable, so this repairs history
        // rather than only new sessions. `$orderId` is an int, so the LIKE takes no operator input,
        // and the pattern is anchored at the end — `%:order:12` cannot match `…:order:123`.
        $payment = Payment::where('gateway', 'paymob')
            ->where(fn ($q) => $q
                ->where('gateway_transaction_id', PaymobPaymentInitiator::orderRef($orderId))
                ->orWhere('gateway_transaction_id', 'like', '%:order:'.$orderId))
            ->first();

        if (! $payment) {
            // Genuinely unknown — most often an order from before a database reset, or a callback
            // for a session this instance never created. We 200 so Paymob stops retrying.
            //
            // The OUTCOME is logged, not just the ids. A dropped failure is noise; a dropped SUCCESS
            // is money taken with nowhere to put it, and until now the two were indistinguishable in
            // the log — which is why the transaction above can no longer be adjudicated at all.
            $success = (bool) data_get($obj, 'success', false);
            $context = [
                'order_id' => $orderId,
                'txn_id' => $txnId,
                'success' => $success,
                'amount_cents' => (int) data_get($obj, 'amount_cents', 0),
            ];

            $success
                ? OpsLog::warning('Paymob reported a SUCCESSFUL payment for an order we cannot match — money may have been taken', $context)
                : OpsLog::info('Paymob callback for unknown order', $context);

            return response()->json(['ok' => true, 'skipped' => 'unknown_order']);
        }

        $success = (bool) data_get($obj, 'success', false);
        $voided = (bool) data_get($obj, 'is_voided', false);
        $isCapture = $success && ! $voided;

        // Is this callback about the transaction we already recorded, or a different one?
        $sameTxn = str_contains((string) $payment->gateway_transaction_id, ":txn:{$txnId}:");

        // **A declined payment is not a closed one.** `failed` used to be terminal here, so even
        // once the lookup above found the row, a shopper who was declined and retried successfully
        // on the same Paymob page had their capture discarded as "already processed" — the money
        // leaves their account and the invoice stays open.
        //
        // `captured` and every REVERSED status stay terminal, and deliberately: a late failure
        // callback must never un-capture collected money, and a void or a refund is an operator's
        // decision that no gateway delivery may reverse.
        //
        // Derived from `Payment::REVERSED_STATUSES`, never re-listed. It WAS re-listed as
        // `['captured', 'failed', 'refunded']`, and when `voided` was split out of `refunded` on
        // 2026-08-28 this list did not hear about it — so a voided receipt stopped being terminal
        // and the next gateway delivery resurrected it to `captured`, re-settling an invoice whose
        // AR had already been re-opened. Caught by `PaymobRetryAfterDeclineTest`, which exists for
        // exactly this. **Enumerate a set like this by asking the model, not by grepping the diff.**
        $isRetryAfterDecline = $payment->status === 'failed' && $isCapture && ! $sameTxn;

        if (! $isRetryAfterDecline && ($payment->status === 'captured' || $payment->status === 'failed' || $payment->isReversed())) {
            // Skipping is right, but a SUCCESS we decline to record is worth a person's attention —
            // a second successful transaction against an already-captured order is a double charge,
            // and nothing else in the system would ever mention it.
            if ($isCapture && ! $sameTxn) {
                OpsLog::warning('Paymob reported a success for a payment already in a terminal state', [
                    'payment_id' => $payment->id,
                    'status' => $payment->status,
                    'order_id' => $orderId,
                    'txn_id' => $txnId,
                    'amount_cents' => (int) data_get($obj, 'amount_cents', 0),
                ]);
            }

            return response()->json(['ok' => true, 'skipped' => 'already_processed']);
        }

        $alreadyProcessed = false;

        DB::transaction(function () use (&$payment, &$alreadyProcessed, $obj, $txnId, $orderId, $isCapture) {
            // Lock the row and re-read the status INSIDE the transaction. The check above is a fast
            // path outside it, which makes this check-then-act: a gateway delivery that overlaps a
            // still-running first delivery passes that check twice, and both callers proceed.
            //
            // Severity, stated honestly: this cannot double-collect. Both deliveries address the
            // same Payment row, so the money is captured once and recomputeTotals is idempotent.
            // What it can do is fire the `saved` hook twice on the same captured transition, which
            // sends the tenant two receipts for one payment. Small — and the same lock-and-re-check
            // discipline every other check-then-act path here already follows.
            $locked = Payment::query()->lockForUpdate()->find($payment->getKey());

            // Re-derived against the LOCKED row, not carried in from the pre-lock read — otherwise
            // the retry allowance would be decided on a status that may have changed since.
            $lockedIsRetry = $locked
                && $locked->status === 'failed'
                && $isCapture
                && ! str_contains((string) $locked->gateway_transaction_id, ":txn:{$txnId}:");

            if (! $locked || (! $lockedIsRetry && ($locked->status === 'captured' || $locked->status === 'failed' || $locked->isReversed()))) {
                $alreadyProcessed = true;

                return;
            }

            $payment = $locked;
            $payment->gateway_transaction_id = "paymob:txn:{$txnId}:order:{$orderId}";
            $payment->gateway_response = $obj;

            if ($isCapture) {
                // A credit applied to the invoice since session-init can shrink its
                // balance below the amount the card was charged. The money is already
                // collected, so accept the payment but clamp its allocation to what
                // still fits — the excess stays unallocated (unearned), never
                // over-allocating the invoice. Runs while status is still 'initiated'
                // so this payment is excluded from the captured-allocation sum. A retry after a
                // decline is `failed` at this point, which is equally not a received status.
                $payment->refitAllocationsToBalance();
            }

            $payment->status = $isCapture ? 'captured' : 'failed';
            $payment->save();
            // Payment::saved hook recomputes invoice totals + fires
            // PaymentReceivedNotification on the captured transition.
        });

        if ($alreadyProcessed) {
            // A racing delivery won. Same answer as the fast path above, so the gateway sees one
            // consistent response either way and stops retrying.
            return response()->json(['ok' => true, 'skipped' => 'already_processed']);
        }

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
