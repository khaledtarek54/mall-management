<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ONLY AN ADMIN LOGIN MAY ACT FOR THE COMPANY — on the app as on the portal (2026-09-05).
 *
 * `TenantUser::isPortalAdmin()` has gated every write in the web portal since the multi-user portal
 * shipped: a retailer's ordinary staff can read, and only an admin submits, declares and pays. The
 * mobile API had no such check and did not need one, because it authenticated the COMPANY — one
 * credential that was, by construction, the admin.
 *
 * Unifying the two logins onto TenantUser removed that guarantee in the dangerous direction: a
 * read-only person who previously could not reach the API AT ALL could suddenly pay an invoice from
 * the phone, publish a marketing post, and edit the company profile. This closes it, and makes the
 * `is_admin` tick on the Portal & App Logins tab mean the same thing on both surfaces.
 *
 * IT GATES BY DEFAULT. A safe method passes, a route that is about the caller's OWN session or
 * device passes by being named below, and everything else needs an admin — so a write route added
 * later is covered by existing rather than by somebody remembering. That is the direction that fails
 * safe: a missing entry refuses a legitimate act loudly, where an allowlist of gated routes would
 * silently leave the next one open.
 */
class EnsurePortalAdminForWrites
{
    /**
     * Writes that are the PERSON's own business, not the company's — so a read-only member of staff
     * must still be able to do them, or they cannot sign out, use their own phone, or stop their
     * notifications piling up.
     *
     * @var array<int, string>
     */
    public const SELF_SCOPED = [
        'api.v1.auth.logout',
        'api.v1.auth.change-password',
        'api.v1.me.devices.store',
        'api.v1.me.devices.destroy',
        'api.v1.me.notifications.read',
        'api.v1.me.notifications.read-all',
        'api.v1.me.announcements.read',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::SELF_SCOPED, true)) {
            return $next($request);
        }

        abort_unless((bool) $request->user()?->is_admin, 403, __('auth.read_only'));

        return $next($request);
    }
}
