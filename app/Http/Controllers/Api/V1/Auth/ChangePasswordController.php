<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Api\V1\Auth\ChangeTenantPasswordAction;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Auth\ChangePasswordRequest;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/auth/change-password — authenticated password change.
 */
class ChangePasswordController extends ApiController
{
    public function __invoke(ChangePasswordRequest $request, ChangeTenantPasswordAction $action): JsonResponse
    {
        $action->handle(
            $request->user(),
            $request->input('current_password'),
            $request->input('password'),
        );

        return $this->ok(message: __('api.password_changed'));
    }
}
