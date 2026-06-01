<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\TenantResource;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me — the authenticated tenant's profile. Alias of auth/me kept
 * under the /me namespace so the mobile client has a consistent resource root.
 */
class ShowProfileController extends ApiController
{
    public function __invoke(Request $request): TenantResource
    {
        return new TenantResource($request->user());
    }
}
