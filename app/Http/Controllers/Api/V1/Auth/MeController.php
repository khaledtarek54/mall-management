<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\TenantResource;
use Illuminate\Http\Request;

/**
 * Returns the currently authenticated tenant. Useful for mobile clients
 * that want to refresh the cached profile after login or app resume.
 */
class MeController extends ApiController
{
    public function __invoke(Request $request): TenantResource
    {
        return new TenantResource($request->user());
    }
}
