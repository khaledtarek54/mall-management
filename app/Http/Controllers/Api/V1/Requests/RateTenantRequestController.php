<?php

namespace App\Http\Controllers\Api\V1\Requests;

use App\Actions\Api\V1\Requests\RateTenantRequestAction;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Requests\RateTenantRequestRequest;
use App\Http\Resources\Api\V1\TenantRequestResource;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/me/requests/{id}/rate — tenant rates a resolved /
 * closed request (CSAT 1–5 + optional comment). Scoped to the caller's own
 * requests; a foreign id 404s (no cross-tenant enumeration). The action defers
 * the resolved/closed guard to the service (→ 422 if not yet rateable).
 */
class RateTenantRequestController extends ApiController
{
    public function __invoke(
        RateTenantRequestRequest $request,
        int $id,
        RateTenantRequestAction $action
    ): JsonResponse {
        $tenantRequest = $request->user()->tenant->tenantRequests()->findOrFail($id);

        $tenantRequest = $action->handle(
            $tenantRequest,
            (int) $request->input('rating'),
            $request->input('comment'),
        );

        return $this->ok(
            new TenantRequestResource($tenantRequest->load('unit')),
            __('api.request_rated'),
        );
    }
}
