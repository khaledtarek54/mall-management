<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Api\V1\Auth\SendTenantPasswordResetLinkAction;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

/**
 * POST /api/v1/auth/forgot-password — email a reset link.
 *
 * Always returns the same generic message whether or not the email is
 * registered (anti-enumeration), except when the broker is throttling, which
 * we surface as 429 so the client can back off.
 */
class ForgotPasswordController extends ApiController
{
    public function __invoke(ForgotPasswordRequest $request, SendTenantPasswordResetLinkAction $action): JsonResponse
    {
        $status = $action->handle($request->email());

        if ($status === Password::RESET_THROTTLED) {
            return $this->ok(message: __('auth.reset_throttled'), status: 429);
        }

        return $this->ok(message: __('auth.reset_link_sent'));
    }
}
