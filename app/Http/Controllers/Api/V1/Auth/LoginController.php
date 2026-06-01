<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Api\Auth\LoginTenantAction;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\LoginLeaseResource;
use Illuminate\Http\JsonResponse;

class LoginController extends ApiController
{
    public function __invoke(LoginRequest $request, LoginTenantAction $action): JsonResponse
    {
        $result = $action->handle(
            email: $request->email(),
            password: $request->password(),
            deviceName: $request->deviceName(),
        );

        // Per the mobile contract: `data` is the leases array; the token rides
        // alongside at the top level (camelCased to accessToken / tokenType).
        return response()->json([
            'data' => LoginLeaseResource::collection($result['leases']),
            'access_token' => $result['token']->plainTextToken,
            'token_type' => 'Bearer',
            'message' => __('auth.login_success'),
        ]);
    }
}
