<?php

namespace App\Http\Controllers\Api\V1\PublicFeed;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Asset;

/**
 * Shared base for the UNAUTHENTICATED shopper endpoints.
 *
 * Everything under `/api/v1/public/*` is reachable by anyone on the internet, so the rules that
 * keep it safe live here rather than being re-typed per controller:
 *
 *  - **The mall is resolved by its public code, and only a real, active mall resolves.** The
 *    `ALL` pseudo-asset is refused explicitly: it is a genuine `assets` row that exists to make
 *    Filament's panel switcher work, and treating it as a mall would return a portfolio-wide feed
 *    from a URL that looks per-property.
 *  - **404 for everything that is not servable** — inactive mall, unknown code, module switched
 *    off, post outside its window. Never 403, never an empty 200 with an explanation. A public
 *    surface should not distinguish "does not exist" from "exists but you may not see it"; the
 *    difference is exactly what an enumeration probe is looking for. Same rule the tenant API
 *    already follows for cross-tenant access.
 */
abstract class PublicFeedController extends ApiController
{
    /** How long a feed response may be served from cache. See ListPublicPostsController. */
    protected const CACHE_SECONDS = 60;

    /**
     * The mall a public URL names, or 404.
     */
    protected function resolveMall(string $code): Asset
    {
        abort_if($code === Asset::ALL_PROPERTIES_CODE, 404);

        $asset = Asset::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        abort_if($asset === null, 404);

        return $asset;
    }
}
