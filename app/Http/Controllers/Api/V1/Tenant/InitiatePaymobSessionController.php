<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PaymobSessionResource;
use App\Models\Invoice;
use App\Services\Paymob\PaymobPaymentInitiator;
use App\Support\InvoiceSettlement;
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
 *  - 403 invoice belongs to another tenant
 *  - 409 Paymob disabled by config
 *  - 422 invoice has no outstanding balance / is cancelled
 *  - 429 throttled (5 sessions per minute per tenant)
 *  - 502 Paymob upstream returned an error
 */
class InitiatePaymobSessionController extends Controller
{
    public function __invoke(
        Request $request,
        Invoice $invoice,
        PaymobPaymentInitiator $initiator,
    ): PaymobSessionResource|JsonResponse {
        $tenant = $request->user();

        if (! config('integrations.paymob.enabled')) {
            return response()->json([
                'message' => __('admin.notifications.pay_now_failed'),
                'error' => 'paymob_disabled',
            ], 409);
        }

        if ((int) $invoice->tenant_id !== (int) $tenant->getKey()) {
            // 404 (not 403) so another tenant's invoice is indistinguishable from
            // a non-existent one — closes cross-tenant invoice-ID enumeration
            // (matches the ShowInvoiceController convention).
            abort(404);
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
        if ($invoice->payableAmount() <= 0) {
            return response()->json([
                'message' => __('admin.notifications.pay_now_failed_body'),
                'error' => 'no_balance',
                'balance' => $invoice->payableAmount(),
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
