<?php

namespace App\Actions\Api\V1\Auth;

use App\Notifications\TenantResetPasswordNotification;
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
        // The callback is what keeps the MOBILE deep link on the mobile flow. Since 2026-09-05 the
        // broker resolves a TenantUser, and only Tenant implemented sendPasswordResetNotification()
        // — so without this, a retailer locked out of the APP is emailed a link into the web portal.
        return Password::broker('tenant_users')->sendResetLink(
            ['email' => $email],
            fn ($user, $token) => $user->notify(new TenantResetPasswordNotification($token)),
        );
    }
}
