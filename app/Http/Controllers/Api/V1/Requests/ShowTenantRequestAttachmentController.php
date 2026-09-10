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
 *
 * Serves BOTH tenant-visible collections on the request — the tenant's own
 * `attachments` and the operator's `resolution_evidence` (SW-249) — because
 * the resource publishes both under this one URL shape. The collection list is
 * the guard for a future operator-only collection; today the model registers
 * no other, so the clause narrows nothing yet (stated, and unexercised in the
 * tests for that reason).
 */
class ShowTenantRequestAttachmentController extends ApiController
{
    public const TENANT_VISIBLE_COLLECTIONS = ['attachments', 'resolution_evidence'];

    /**
     * Stream one file from your own request — its `attachments` (what you reported) or its
     * `resolutionEvidence` (what was done). A foreign request id, or a file outside those two
     * lists, is a 404.
     */
    public function __invoke(Request $request, int $id, int $media): StreamedResponse
    {
        /** @var TenantRequest $tenantRequest */
        $tenantRequest = $request->user()->tenant->tenantRequests()->findOrFail($id);

        $item = $tenantRequest->media
            ->whereIn('collection_name', self::TENANT_VISIBLE_COLLECTIONS)
            ->firstWhere('id', $media);
        abort_if($item === null, 404);

        return $item->toInlineResponse($request);
    }
}
