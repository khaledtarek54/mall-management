<?php

namespace App\Http\Controllers\Api\V1\Maintenance;

use App\Actions\Api\V1\Maintenance\RateMaintenanceRequestAction;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Maintenance\RateMaintenanceRequestRequest;
use App\Http\Resources\Api\V1\MaintenanceRequestResource;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/me/maintenance-requests/{id}/rate — tenant rates a resolved /
 * closed request (CSAT 1–5 + optional comment). Scoped to the caller's own
 * requests; a foreign id 404s (no cross-tenant enumeration). The action defers
 * the resolved/closed guard to the service (→ 422 if not yet rateable).
 */
class RateMaintenanceRequestController extends ApiController
{
    public function __invoke(
        RateMaintenanceRequestRequest $request,
        int $id,
        RateMaintenanceRequestAction $action
    ): JsonResponse {
        $maintenanceRequest = $request->user()->maintenanceRequests()->findOrFail($id);

        $maintenanceRequest = $action->handle(
            $maintenanceRequest,
            (int) $request->input('rating'),
            $request->input('comment'),
        );

        return $this->ok(
            new MaintenanceRequestResource($maintenanceRequest->load('unit')),
            __('api.maintenance_rated'),
        );
    }
}
