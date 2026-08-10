<?php

namespace App\Http\Middleware;

use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns the shopper feed off at the ROUTE, not just in the sidebar.
 *
 * `RoleGatedActions` already composes the module flag into every admin permission check, so
 * disabling `marketing_posts` hides the resource. That does nothing for the public API, which has
 * no user and no permission to gate — an operator who switched the module off would still be
 * serving offers to anyone who knew the URL, which is the opposite of what the switch means.
 *
 * 404, not 403: the endpoint should be indistinguishable from one that was never built. A 403
 * confirms the route exists and the feature is merely off, which is information a public surface
 * has no reason to hand out.
 */
class EnsureMarketingPostsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Modules::enabled('marketing_posts'), 404);

        return $next($request);
    }
}
