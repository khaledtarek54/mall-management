<?php

namespace App\Actions\Api\V1\Auth;

use App\Models\Tenant;
use Illuminate\Support\Facades\Password;

/**
 * Apply a password reset using a token from the reset email. On success it
 * sets the new password (hashed by the model cast) and revokes ALL of the
 * tenant's tokens — a reset means "I lost access", so every device re-auths.
 *
 * Returns the broker status string for the controller to translate.
 */
class ResetTenantPasswordAction
{
    /**
     * @param  array<string,string>  $credentials  Keys: email, password, password_confirmation, token
     */
    public function handle(array $credentials): string
    {
        return Password::broker('tenants')->reset(
            $credentials,
            function (Tenant $tenant, string $password) {
                $tenant->update(['password' => $password]);
                $tenant->tokens()->delete();
            },
        );
    }
}
