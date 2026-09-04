<?php

namespace App\Actions\Api\V1\Auth;

use App\Models\TenantUser;
use Illuminate\Support\Facades\Password;

/**
 * Apply a password reset using a token from the reset email. On success it
 * sets the new password (hashed by the model cast) and revokes ALL of that
 * person's tokens — a reset means "I lost access", so every device re-auths.
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
        return Password::broker('tenant_users')->reset(
            $credentials,
            // The broker resolves a TenantUser since 2026-09-05 — typing this closure for the
            // company made every reset a 500 the moment the guard moved.
            function (TenantUser $user, string $password) {
                $user->update(['password' => $password]);
                $user->tokens()->delete();
            },
        );
    }
}
