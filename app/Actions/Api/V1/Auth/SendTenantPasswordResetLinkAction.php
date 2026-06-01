<?php

namespace App\Actions\Api\V1\Auth;

use Illuminate\Support\Facades\Password;

/**
 * Trigger a password-reset email for a tenant via the dedicated `tenants`
 * broker. Returns the broker status string; the controller maps it to a
 * generic message so the endpoint never reveals whether an email is registered
 * (anti-enumeration).
 */
class SendTenantPasswordResetLinkAction
{
    public function handle(string $email): string
    {
        return Password::broker('tenants')->sendResetLink(['email' => $email]);
    }
}
