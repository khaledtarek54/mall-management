<?php

namespace App\Actions\Api\V1\Auth;

use App\Notifications\TenantResetPasswordNotification;
use Illuminate\Support\Facades\Password;

/**
 * Trigger a password-reset email for a tenant via the dedicated `tenants`
 * broker. Returns the broker status string; the controller maps `RESET_LINK_SENT` and
 * `INVALID_USER` to one generic message (anti-enumeration) — but NOT `RESET_THROTTLED`, which
 * the broker answers only for an address that EXISTS and was asked recently, and the controller
 * surfaces as a 429. So a repeat request still tells a caller whether the address is registered.
 * Recorded in docs/modules/20-mobile-api.md as an open decision (2026-09-11); the fix is to answer
 * that status with the same generic 200.
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
