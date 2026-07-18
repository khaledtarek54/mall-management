<?php

namespace App\Http\Controllers\Api\V1\Requests;

use App\Actions\Api\V1\Requests\CancelTenantRequestAction;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\TenantRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/me/requests/{id}/cancel — tenant cancels a request
 * that hasn't been started yet. The action enforces the can-cancel rule.
 */
class CancelTenantRequestController extends ApiController
{
    public function __invoke(
        Request $request,
        int $id,
        CancelTenantRequestAction $action
    ): JsonResponse {
        $maintenanceRequest = $request->user()->maintenanceRequests()->findOrFail($id);

        $maintenanceRequest = $action->handle($maintenanceRequest);

        return $this->ok(
            new TenantRequestResource($maintenanceRequest->load('unit')),
            __('api.maintenance_cancelled'),
        );
    }
}
