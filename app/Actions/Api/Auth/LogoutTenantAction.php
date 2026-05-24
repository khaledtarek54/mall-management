<?php

namespace App\Actions\Api\Auth;

use App\Models\Tenant;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Revoke the access token used to make the current request.
 * For "log out everywhere" semantics, call $tenant->tokens()->delete() directly.
 */
class LogoutTenantAction
{
    public function handle(Tenant $tenant, PersonalAccessToken $currentToken): void
    {
        $currentToken->delete();
    }
}
