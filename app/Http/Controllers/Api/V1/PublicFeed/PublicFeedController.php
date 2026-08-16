<?php

namespace App\Http\Controllers\Api\V1\PublicFeed;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Asset;
use App\Models\MarketingPost;
use App\Models\Tenant;

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
     * Stamp each post's store with the unit code(s) it occupies IN THIS MALL.
     *
     * The card is the screen a shopper acts on — "Cilantro, 20% off" is only useful next to
     * "Unit A-01". The directory endpoints resolved this and the feed did not, so the same store
     * block carried a location on one screen and not the other; a client would have had to fetch
     * the directory just to label a card.
     *
     * Scoped to the mall being browsed, exactly as in the directory: never the retailer's
     * footprint across the operator's whole portfolio.
     *
     * @param  iterable<int, MarketingPost>  $posts
     */
    protected function attachStoreLocations(iterable $posts, int $assetId): void
    {
        foreach ($posts as $post) {
            if ($post->tenant === null) {
                continue; // Mall-wide post — no store, nothing to locate.
            }

            $post->tenant->public_locations = self::locationsFor($post->tenant, $assetId);
        }
    }

    /**
     * The unit code(s) a retailer occupies in ONE mall.
     *
     * The single definition, shared by the feed, the offer detail and both directory endpoints —
     * four callers that must agree about where a shop is. It was written out three times before
     * the feed needed it, which is three chances for one of them to start answering differently.
     *
     * `activeLeases.units` is the `lease_unit` pivot, so a unit held as an ADDITIONAL unit on a
     * multi-unit lease is included; the `where` then narrows to the mall being browsed, which is
     * what stops a public endpoint mapping a chain across the whole portfolio.
     *
     * @return array<int, string>
     */
    protected static function locationsFor(Tenant $tenant, int $assetId): array
    {
        return $tenant->activeLeases
            ->flatMap(fn ($lease) => $lease->units)
            ->where('asset_id', $assetId)
            ->pluck('code')
            ->unique()
            ->values()
            ->all();
    }

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
