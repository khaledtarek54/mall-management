<?php

namespace App\Http\Controllers\Api\V1\Maintenance;

use App\Actions\Api\V1\Maintenance\CancelMaintenanceRequestAction;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\MaintenanceRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/me/maintenance-requests/{id}/cancel — tenant cancels a request
 * that hasn't been started yet. The action enforces the can-cancel rule.
 */
class CancelMaintenanceRequestController extends ApiController
{
    public function __invoke(
        Request $request,
        int $id,
        CancelMaintenanceRequestAction $action
    ): JsonResponse {
        $maintenanceRequest = $request->user()->maintenanceRequests()->findOrFail($id);

        $maintenanceRequest = $action->handle($maintenanceRequest);

        return $this->ok(
            new MaintenanceRequestResource($maintenanceRequest->load('unit')),
            __('api.maintenance_cancelled'),
        );
    }
}
