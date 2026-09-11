<?php

namespace App\Http\Middleware;

use App\Exceptions\CodedHttpException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-check the authenticated tenant's status on EVERY authenticated mobile-API request. Login
 * (LoginTenantAction) blocks a non-active company, but a company blacklisted/deactivated DURING a
 * live session kept full API access because its Sanctum token was never re-validated. This closes
 * that window: if the company is no longer 'active', revoke the current token and return the same
 * 403 the login uses (drives the app's Blocked screen) — coded `tenant_inactive`, so the app can tell
 * it from a read-only login's 403 without guessing from the method. A soft-deleted company is caught
 * here too: since 2026-09-05 the token belongs to a PERSON, whose login still resolves, so `->tenant`
 * answers null and they are refused and revoked like a blocked one.
 */
class EnsureTenantActive
{
    public function handle(Request $request, Closure $next): Response
    {
        // `user()` is the TenantUser since 2026-09-05; the standing re-checked here is the
        // COMPANY's, which is what the app is blocked on.
        $tenant = $request->user()?->tenant;

        if (! $tenant || $tenant->status !== 'active') {
            $request->user()?->currentAccessToken()?->delete();

            throw new CodedHttpException(403, __('auth.account_blocked'), 'tenant_inactive');
        }

        return $next($request);
    }
}
