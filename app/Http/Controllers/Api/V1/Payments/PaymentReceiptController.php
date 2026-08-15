<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Payment;
use App\Services\ReceiptPdfService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/me/payments/{id}/receipt — streams the payment RECEIPT VOUCHER (سند قبض).
 *
 * The proof a tenant screenshots. It reuses the exact {@see ReceiptPdfService} the admin table and
 * the portal's ViewPayment page call, so all three surfaces hand out a byte-identical document —
 * the same rule {@see \App\Http\Controllers\Api\V1\Invoices\InvoicePdfController} follows. Locale
 * follows `Accept-Language` (SetApiLocale), which is what switches the PDF to RTL.
 *
 * **Gated on `isReceived()`, the same predicate the portal action gates on**, named once so the two
 * cannot drift: a receipt is a cash-RECEIVED acknowledgement, so issuing one for an initiated or
 * failed payment would be a document asserting money arrived when it did not. `422` rather than
 * `404` there, because the payment genuinely exists and the refusal has a reason the app can show —
 * a 404 would read as "we lost your payment".
 *
 * A cross-tenant id resolves to **404** through the relationship scope: no existence enumeration,
 * same as every other `/me/*` detail route.
 */
class PaymentReceiptController extends ApiController
{
    public function __invoke(Request $request, int $id, ReceiptPdfService $pdf): Response
    {
        /** @var Payment $payment */
        $payment = $request->user()->payments()->findOrFail($id);

        abort_unless($payment->isReceived(), 422, __('api.payment_receipt_not_available'));

        return $this->streamPdf($pdf->build($payment), $pdf->filename($payment));
    }
}
