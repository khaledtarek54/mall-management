<?php

namespace App\Http\Controllers\Api\V1\Requests;

use App\Actions\Api\V1\Requests\ConfirmTenantRequestAction;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\TenantRequestResource;
use App\Support\Portal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/me/requests/{id}/confirm — the tenant accepts that the work is done, closing the
 * request and recording who accepted.
 *
 * Scoped to the caller's own requests; a foreign id 404s (no cross-tenant enumeration). The
 * `resolved`-only guard is the service's, so this and the portal refuse identically (→ 422).
 */
class ConfirmTenantRequestController extends ApiController
{
    public function __invoke(Request $request, int $id, ConfirmTenantRequestAction $action): JsonResponse
    {
        $tenantRequest = $request->user()->tenant->tenantRequests()->findOrFail($id);

        $tenantRequest = $action->handle($tenantRequest, Portal::user());

        return $this->ok(
            new TenantRequestResource($tenantRequest->load('unit')),
            __('api.request_confirmed'),
        );
    }
}
