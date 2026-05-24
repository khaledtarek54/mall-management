<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Api\Auth\LoginTenantAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\TenantResource;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    public function __invoke(LoginRequest $request, LoginTenantAction $action): JsonResponse
    {
        $result = $action->handle(
            email: $request->email(),
            password: $request->password(),
            deviceName: $request->deviceName(),
        );

        return response()->json([
            'data' => [
                'tenant' => new TenantResource($result['tenant']),
                'token' => $result['token']->plainTextToken,
                'token_type' => 'Bearer',
            ],
            'message' => __('auth.login_success'),
        ]);
    }
}
