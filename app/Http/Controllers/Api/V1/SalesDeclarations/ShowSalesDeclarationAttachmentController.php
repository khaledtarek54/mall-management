<?php

namespace App\Http\Controllers\Api\V1\SalesDeclarations;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\TenantSalesDeclaration;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/v1/me/sales-declarations/{id}/attachments/{media} — streams a
 * sales-report file from the PRIVATE disk, gated to the caller's own
 * declarations (a foreign declaration id 404s — no cross-tenant file
 * disclosure of commercial turnover figures).
 */
class ShowSalesDeclarationAttachmentController extends ApiController
{
    public function __invoke(Request $request, int $id, int $media): StreamedResponse
    {
        /** @var TenantSalesDeclaration $declaration */
        $declaration = $request->user()->tenant->salesDeclarations()->findOrFail($id);

        $item = $declaration->getMedia(TenantSalesDeclaration::REPORT_COLLECTION)->firstWhere('id', $media);
        abort_if($item === null, 404);

        return $item->toInlineResponse($request);
    }
}
