<?php

namespace App\Http\Controllers\Api\V1\Requests;

use App\Actions\Api\V1\Requests\DisputeTenantRequestAction;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Requests\DisputeTenantRequestRequest;
use App\Http\Resources\Api\V1\TenantRequestResource;
use App\Support\Portal;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/me/requests/{id}/dispute — the tenant says it is not fixed, returning the request to
 * the operator with their reason on the comment thread.
 *
 * The mobile app is where a shop manager actually is, so a confirmation control that existed only
 * on the desktop portal would be one most tenants never used.
 */
class DisputeTenantRequestController extends ApiController
{
    public function __invoke(
        DisputeTenantRequestRequest $request,
        int $id,
        DisputeTenantRequestAction $action
    ): JsonResponse {
        $tenantRequest = $request->user()->tenantRequests()->findOrFail($id);

        $tenantRequest = $action->handle($tenantRequest, Portal::user(), (string) $request->input('reason'));

        return $this->ok(
            new TenantRequestResource($tenantRequest->load('unit')),
            __('api.request_disputed'),
        );
    }
}
