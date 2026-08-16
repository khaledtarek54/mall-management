<?php

namespace App\Http\Controllers\Api\V1\Invoices;

use App\Http\Controllers\Api\V1\ApiController;
use App\Services\InvoicePdfService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/me/invoices/{id}/pdf — streams the bilingual invoice PDF.
 * Reuses the exact same InvoicePdfService the admin + portal use, so the
 * mobile PDF is byte-identical. Locale follows the Accept-Language header.
 */
class InvoicePdfController extends ApiController
{
    public function __invoke(Request $request, int $id, InvoicePdfService $pdf): Response
    {
        $invoice = $request->user()->invoices()->visibleToTenant()->findOrFail($id);

        return $this->streamPdf($pdf->build($invoice), $pdf->filename($invoice));
    }
}
