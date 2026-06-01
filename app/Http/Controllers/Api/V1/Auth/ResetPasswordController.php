<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Api\V1\Auth\ResetTenantPasswordAction;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/auth/reset-password — apply a reset token + new password.
 */
class ResetPasswordController extends ApiController
{
    public function __invoke(ResetPasswordRequest $request, ResetTenantPasswordAction $action): JsonResponse
    {
        $status = $action->handle($request->credentials());

        if ($status !== Password::PASSWORD_RESET) {
            // Invalid/expired token or unknown email — one generic 422.
            throw ValidationException::withMessages([
                'email' => [__('auth.reset_failed')],
            ]);
        }

        return $this->ok(message: __('auth.reset_success'));
    }
}
