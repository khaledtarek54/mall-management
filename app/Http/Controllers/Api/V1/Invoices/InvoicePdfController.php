<?php

namespace App\Http\Controllers\Api\V1\Invoices;

use App\Http\Controllers\Api\V1\ApiController;
use App\Services\InvoicePdfService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/me/invoices/{id}/pdf — streams the bilingual invoice PDF.
 * Reuses the exact same InvoicePdfService the admin + portal use, so the
 * mobile PDF is byte-identical. Locale follows `Accept-Language`, or `?lang=en|ar` when the
 * client wants one document in the other language without changing its headers.
 */
class InvoicePdfController extends ApiController
{
    public function __invoke(Request $request, int $id, InvoicePdfService $pdf): Response
    {
        $invoice = $request->user()->tenant->invoices()->visibleToTenant()->findOrFail($id);

        return $this->streamPdf(
            $pdf->build($invoice, $this->documentLocale($request)),
            $pdf->filename($invoice),
        );
    }
}
