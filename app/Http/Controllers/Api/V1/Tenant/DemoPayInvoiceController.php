<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Actions\Api\V1\Payments\RecordDemoPaymentAction;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Models\Invoice;
use App\Support\DemoPayments;
use App\Support\InvoiceSettlement;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Demo payment: marks an invoice paid without a real gateway round-trip.
 *
 *   POST /api/v1/me/invoices/{invoice}/pay-demo
 *
 * Only available while Paymob is disabled (PAYMOB_ENABLED=false) — the demo
 * shortcut for environments with no live PSP. Once Paymob is enabled this
 * returns 409 and clients must use the paymob-session flow instead. The
 * backend, not the app, "assumes" success: the resulting Payment goes through
 * the exact capture path the real callback uses, so the invoice flips to
 * paid and the tenant gets the standard payment-received notification.
 *
 * Guards mirror InitiatePaymobSessionController:
 *  - 403 coded `read_only` — a read-only login may not pay (EnsurePortalAdminForWrites)
 *  - 404 invoice belongs to another tenant, or does not exist — resolved inside, after the
 *    read-only gate, so the two are indistinguishable to every login
 *  - 409 Paymob is enabled — use the real flow
 *  - 422 invoice not payable / no outstanding balance
 */
class DemoPayInvoiceController extends ApiController
{
    /**
     * @param  int  $invoice  The invoice ID
     *
     * @throws ModelNotFoundException 404 — the invoice is not this tenant's, or does not exist
     */
    public function __invoke(
        Request $request,
        int $invoice,
        RecordDemoPaymentAction $action,
    ): JsonResponse {
        $tenant = $request->user()->tenant;

        // Through the tenant's own invoices, never an implicit `Invoice $invoice` binding — the
        // binding resolves before `EnsurePortalAdminForWrites` runs and handed a read-only login a
        // 403-or-404 that said whether ANY tenant's invoice id exists. See the note in
        // InitiatePaymobSessionController; the two mirror each other. First, so a missing id answers
        // 404 whether or not demo payments are enabled, exactly as the binding did.
        /** @var Invoice $invoice */
        $invoice = $tenant->invoices()->findOrFail($invoice);

        // One predicate, asked in one place — App\Support\DemoPayments. Gating this on
        // `paymob.enabled` alone meant the endpoint was live precisely on a production box with no
        // gateway configured, which is the shipped default and the documented incident posture.
        if (! DemoPayments::enabled()) {
            return response()->json([
                'message' => __('admin.notifications.pay_now_failed'),
                'error' => 'use_real_payment',
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

        $payment = $action->handle($invoice);

        return (new PaymentResource($payment))
            ->additional(['message' => __('admin.notifications.payment_received_title')])
            ->response()
            ->setStatusCode(201);
    }
}
