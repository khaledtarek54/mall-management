<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-check the authenticated tenant's status on EVERY authenticated mobile-API request. Login
 * (LoginTenantAction) blocks a non-active company, but a company blacklisted/deactivated DURING a
 * live session kept full API access because its Sanctum token was never re-validated. This closes
 * that window: if the company is no longer 'active', revoke the current token and return the same
 * 403 the login uses (drives the app's Blocked screen). A soft-deleted company already fails
 * `auth:tenant-api` (the provider can't resolve it), so this handles the blacklist/inactive case.
 */
class EnsureTenantActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->user();

        if (! $tenant || $tenant->status !== 'active') {
            $request->user()?->currentAccessToken()?->delete();
            abort(403, __('auth.account_blocked'));
        }

        return $next($request);
    }
}
