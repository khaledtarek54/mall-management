<?php

namespace App\Http\Controllers\Api\V1\Maintenance;

use App\Actions\Api\V1\Maintenance\CreateMaintenanceRequestAction;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Maintenance\CreateMaintenanceRequestRequest;
use App\Http\Resources\Api\V1\MaintenanceRequestResource;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/me/maintenance-requests — submit a new request.
 */
class CreateMaintenanceRequestController extends ApiController
{
    public function __invoke(
        CreateMaintenanceRequestRequest $request,
        CreateMaintenanceRequestAction $action
    ): JsonResponse {
        $maintenanceRequest = $action->handle($request->user(), $request->payload());

        return $this->ok(
            new MaintenanceRequestResource($maintenanceRequest),
            __('api.maintenance_created'),
            201,
        );
    }
}
