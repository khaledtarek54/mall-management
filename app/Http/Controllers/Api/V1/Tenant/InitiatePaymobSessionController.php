<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PaymobSessionResource;
use App\Models\Invoice;
use App\Services\Paymob\PaymobPaymentInitiator;
use App\Support\InvoiceSettlement;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issues a Paymob payment session for a single invoice belonging to the
 * authenticated tenant. The mobile client uses the session to either:
 *
 *  - hand the payment_token to the Paymob Flutter SDK (native card form,
 *    Apple/Google Pay), or
 *  - open the iframe_url in a WebView.
 *
 * In both flows the authoritative status update comes from the S2S
 * /paymob/callback webhook (HMAC-verified). The mobile client should poll
 * GET /api/v1/invoices/{id} (or its existing equivalent) to see the invoice
 * flip to 'paid' rather than trusting the SDK's local result.
 *
 * Guards:
 *  - 401 unauthenticated (handled by auth:tenant-api middleware)
 *  - 403 coded `read_only` — a read-only login may not start a payment (EnsurePortalAdminForWrites)
 *  - 404 invoice belongs to another tenant, or does not exist — resolved inside, after the
 *    read-only gate, so the two are indistinguishable to every login (see `__invoke`)
 *  - 409 Paymob disabled by config
 *  - 422 invoice has no outstanding balance / is cancelled
 *  - 429 throttled — the authenticated surface's 60 a minute per login (`throttle:60,1,api-me`),
 *    shared with every other /me route; there is no tighter limit of its own
 *  - 502 Paymob upstream returned an error
 */
class InitiatePaymobSessionController extends Controller
{
    /**
     * @param  int  $invoice  The invoice ID
     *
     * @throws ModelNotFoundException 404 — the invoice is not this tenant's, or does not exist
     */
    public function __invoke(
        Request $request,
        int $invoice,
        PaymobPaymentInitiator $initiator,
    ): PaymobSessionResource|JsonResponse {
        $tenant = $request->user()->tenant;

        // Resolved HERE, through the tenant's own invoices, and deliberately NOT by implicit
        // binding (`Invoice $invoice`). `SubstituteBindings` sits in Laravel's priority list ahead of
        // `EnsurePortalAdminForWrites`, so a bound parameter was looked up — unscoped — before the
        // read-only gate ran: a read-only login got 403 `read_only` for any invoice id that EXISTS,
        // anyone's, and 404 for one that does not. That is the cross-tenant existence oracle the
        // 404-not-403 convention below exists to close, through a different door. Now nothing is
        // looked up until the gate has passed, and another tenant's invoice is indistinguishable
        // from a non-existent one (matches the ShowInvoiceController convention). First, before the
        // gateway check, where the binding used to be — so a missing id answers 404 whether or not
        // Paymob is enabled, exactly as before.
        /** @var Invoice $invoice */
        $invoice = $tenant->invoices()->findOrFail($invoice);

        if (! config('integrations.paymob.enabled')) {
            return response()->json([
                'message' => __('admin.notifications.pay_now_failed'),
                'error' => 'paymob_disabled',
            ], 409);
        }

        // `InvoiceSettlement`, not a fifth copy of the same four statuses. This list and its three
        // siblings were four hand-rolled answers to one question, and the register that owns it
        // carries a written reason against every status on both sides of the partition.
        if (! InvoiceSettlement::accepts($invoice)) {
            return response()->json([
                'message' => __('admin.notifications.pay_now_failed_body'),
                'error' => 'invoice_not_payable',
                'status' => $invoice->status,
            ], 422);
        }

        // `payableAmount()`, never the raw balance: a write-off deliberately leaves `balance`
        // standing, so a partly-forgiven invoice passed this guard and then went to the gateway for
        // money nobody was claiming. It must ALSO be the same predicate the action behind it uses,
        // or the client gets that action's bare 422 with none of the keys this contract promises.
        // The `(float)` on `balance` below is for the SPEC, not the value — `payableAmount()` already
        // returns a float. With the invoice resolved in-method rather than route-bound, Scramble no
        // longer sees its type and would publish that money field as `string`, which the Flutter
        // decoder throws on. (A comment directly above the key would become the field's description.)
        if ($invoice->payableAmount() <= 0) {
            return response()->json([
                'message' => __('admin.notifications.pay_now_failed_body'),
                'error' => 'no_balance',
                'balance' => (float) $invoice->payableAmount(),
            ], 422);
        }

        try {
            $session = $initiator->start($invoice);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => __('admin.notifications.pay_now_failed'),
                'error' => 'paymob_upstream_error',
            ], 502);
        }

        return new PaymobSessionResource($session);
    }
}
