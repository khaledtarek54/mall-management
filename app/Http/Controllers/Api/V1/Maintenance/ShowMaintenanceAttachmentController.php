<?php

namespace App\Http\Controllers\Api\V1\Maintenance;

use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/v1/me/maintenance-requests/{id}/attachments/{media} — streams a
 * request attachment from the PRIVATE disk, gated to the caller's own requests
 * (a foreign request id 404s — no cross-tenant file disclosure). Replaces the
 * old public, enumerable getFullUrl() (hardening backlog H2).
 */
class ShowMaintenanceAttachmentController extends ApiController
{
    public function __invoke(Request $request, int $id, int $media): StreamedResponse
    {
        /** @var \App\Models\TenantRequest $maintenanceRequest */
        $maintenanceRequest = $request->user()->maintenanceRequests()->findOrFail($id);

        $item = $maintenanceRequest->getMedia('attachments')->firstWhere('id', $media);
        abort_if($item === null, 404);

        return $item->toInlineResponse($request);
    }
}
