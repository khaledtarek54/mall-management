<?php

namespace App\Http\Controllers\Api\V1\Requests;

use App\Actions\Api\V1\Requests\CreateTenantRequestAction;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Requests\CreateTenantRequestRequest;
use App\Http\Resources\Api\V1\TenantRequestResource;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/me/requests — submit a new request.
 */
class CreateTenantRequestController extends ApiController
{
    public function __invoke(
        CreateTenantRequestRequest $request,
        CreateTenantRequestAction $action
    ): JsonResponse {
        $maintenanceRequest = $action->handle(
            $request->user(),
            $request->payload(),
            $request->attachments(),
        );

        return $this->ok(
            new TenantRequestResource($maintenanceRequest),
            __('api.request_created'),
            201,
        );
    }
}
