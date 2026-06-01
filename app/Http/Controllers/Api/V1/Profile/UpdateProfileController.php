<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Actions\Api\V1\Profile\UpdateTenantProfileAction;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Profile\UpdateProfileRequest;
use App\Http\Resources\Api\V1\TenantResource;
use Illuminate\Http\JsonResponse;

/**
 * PATCH /api/v1/me — update the tenant's own contact fields.
 */
class UpdateProfileController extends ApiController
{
    public function __invoke(UpdateProfileRequest $request, UpdateTenantProfileAction $action): JsonResponse
    {
        $tenant = $action->handle($request->user(), $request->editableData());

        return $this->ok(new TenantResource($tenant), __('api.profile_updated'));
    }
}
