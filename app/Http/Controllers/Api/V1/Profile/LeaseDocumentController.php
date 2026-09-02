<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Lease;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/me/leases/{id}/document — the tenant's own SIGNED lease.
 *
 * The web portal has offered this since its lease table shipped (`downloadDocument`); the app had
 * no counterpart, so a tenant who wanted their own contract had to raise a `document` request and
 * wait for a person to e-mail it back.
 *
 * Three things it deliberately does, each matching an existing rule on this surface:
 *
 *   - **`visibleToTenant()`**, so a DRAFT lease's paperwork is not reachable. The lease picker was
 *     fixed for exactly this on 2026-09-02; a document route that skipped it would reopen the hole
 *     through the other door.
 *   - **404, never 403**, for another tenant's lease — the whole-surface convention against
 *     existence enumeration.
 *   - **Streams from the PRIVATE disk** (`Lease::DOCUMENTS_COLLECTION` is `useDisk('local')`), so
 *     this needs the `Authorization` header like any other endpoint. It is not a public URL.
 *
 * The LAST uploaded document wins, exactly as the portal action picks it: an operator re-uploading
 * a countersigned copy means the newest one is the lease, not a second lease.
 */
class LeaseDocumentController extends ApiController
{
    public function __invoke(Request $request, int $id): Response
    {
        /** @var Lease $lease */
        $lease = $request->user()->leases()->visibleToTenant()->findOrFail($id);

        $media = $lease->getMedia(Lease::DOCUMENTS_COLLECTION)->last();

        // No document uploaded is a 404 rather than an empty 200: the resource's `has_document`
        // flag is what the client gates the button on, and an empty body would read as a corrupt
        // file rather than as "the operator has not uploaded one".
        abort_if($media === null, 404);

        return $media->toResponse($request);
    }
}
