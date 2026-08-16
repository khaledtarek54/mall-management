<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Actions\Api\V1\Payments\RecordDemoPaymentAction;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Models\Invoice;
use App\Support\DemoPayments;
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
 *  - 403 invoice belongs to another tenant
 *  - 409 Paymob is enabled — use the real flow
 *  - 422 invoice not payable / no outstanding balance
 */
class DemoPayInvoiceController extends ApiController
{
    public function __invoke(
        Request $request,
        Invoice $invoice,
        RecordDemoPaymentAction $action,
    ): JsonResponse {
        $tenant = $request->user();

        // One predicate, asked in one place — App\Support\DemoPayments. Gating this on
        // `paymob.enabled` alone meant the endpoint was live precisely on a production box with no
        // gateway configured, which is the shipped default and the documented incident posture.
        if (! DemoPayments::enabled()) {
            return response()->json([
                'message' => __('admin.notifications.pay_now_failed'),
                'error' => 'use_real_payment',
            ], 409);
        }

        if ((int) $invoice->tenant_id !== (int) $tenant->getKey()) {
            // 404 (not 403) so another tenant's invoice is indistinguishable from
            // a non-existent one — closes cross-tenant invoice-ID enumeration.
            abort(404);
        }

        if (in_array($invoice->status, ['draft', 'cancelled', 'credited', 'written_off'], true)) {
            return response()->json([
                'message' => __('admin.notifications.pay_now_failed_body'),
                'error' => 'invoice_not_payable',
                'status' => $invoice->status,
            ], 422);
        }

        if ((float) $invoice->balance <= 0) {
            return response()->json([
                'message' => __('admin.notifications.pay_now_failed_body'),
                'error' => 'no_balance',
                'balance' => (float) $invoice->balance,
            ], 422);
        }

        $payment = $action->handle($invoice);

        return (new PaymentResource($payment))
            ->additional(['message' => __('admin.notifications.payment_received_title')])
            ->response()
            ->setStatusCode(201);
    }
}
