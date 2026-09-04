<?php

namespace App\Http\Controllers\Api\V1\CamAllocations;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\CamAllocation;
use App\Services\CamStatementPdfService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/me/cam-allocations/{id}/statement — the service-charge statement PDF.
 *
 * The same service the portal's download action uses, so all three surfaces hand the tenant a
 * byte-identical file. **This is where a service-charge audit right is actually exercised** — a
 * statement only the operator can print is a statement the tenant has to ask for — and the app is
 * where the shop manager is.
 *
 * Locale follows `Accept-Language`, or `?lang=en|ar`; the request wins over the tenant's stored
 * locale here for the reason `ApiController::documentLocale()` gives — on the API the caller IS the
 * recipient.
 */
class CamStatementController extends ApiController
{
    public function __invoke(Request $request, int $id, CamStatementPdfService $pdf): Response
    {
        $allocation = CamAllocation::ownedBy($request->user()->tenant)->findOrFail($id);

        return $this->streamPdf(
            $pdf->build($allocation, $this->documentLocale($request)),
            $pdf->filename($allocation),
        );
    }
}
