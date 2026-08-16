<?php

namespace App\Http\Controllers\Api\V1\Requests;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\TenantRequest;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/v1/me/requests/{id}/attachments/{media} — streams a
 * request attachment from the PRIVATE disk, gated to the caller's own requests
 * (a foreign request id 404s — no cross-tenant file disclosure). Replaces the
 * old public, enumerable getFullUrl() (hardening backlog H2).
 */
class ShowTenantRequestAttachmentController extends ApiController
{
    public function __invoke(Request $request, int $id, int $media): StreamedResponse
    {
        /** @var TenantRequest $tenantRequest */
        $tenantRequest = $request->user()->tenantRequests()->findOrFail($id);

        $item = $tenantRequest->getMedia('attachments')->firstWhere('id', $media);
        abort_if($item === null, 404);

        return $item->toInlineResponse($request);
    }
}
