<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Cache versioning for the public shopper feed.
 *
 * The feed is the one endpoint every shopper hits on app open and is identical for all of them, so
 * it is cached. The question is how it gets invalidated, and the two obvious answers are both
 * wrong on their own:
 *
 *  - **TTL only** is safe but slow to react. An operator approves a retailer's offer, opens the
 *    app to check, and sees nothing for up to a minute. They will conclude it did not work — and
 *    the second thing they do is approve it again.
 *  - **Event-based busting only** is instant but fragile. It needs a hook on publish, archive,
 *    edit, media change, un-delete and the expiry sweep, and whichever one is forgotten serves a
 *    stale feed *indefinitely*, with nothing to notice it.
 *
 * So: both. The cache key carries a per-property version counter that the publish and archive
 * paths bump, and the entry still expires on its own. Forgetting to bump costs at most the TTL;
 * remembering makes it immediate. Correctness does not depend on anyone remembering, which is the
 * property that matters when someone adds the seventh way a post can change.
 *
 * Per-property rather than global: one mall publishing an offer should not throw away every other
 * mall's cached feed.
 */
class MarketingFeedCache
{
    /** How long a cached feed response lives, absent any bump. */
    public const TTL_SECONDS = 60;

    /**
     * The current version token for one property's feed. Part of every cache key, so bumping it
     * orphans the old entries rather than deleting them — no key enumeration required, which is
     * what makes this work on a cache store that cannot list keys (Redis in production, array in
     * tests).
     */
    public static function version(int $assetId): int
    {
        return (int) Cache::get(self::key($assetId), 1);
    }

    /** Called whenever a property's feed content changes. Cheap, and safe to over-call. */
    public static function bump(int $assetId): void
    {
        $key = self::key($assetId);

        // add() then increment() rather than a bare increment: on a store where the key is absent
        // increment() is a no-op on some drivers, which would silently freeze the version at its
        // default and turn this back into TTL-only invalidation.
        Cache::add($key, 1);
        Cache::increment($key);
    }

    /**
     * A retailer's directory entry changed — bump every mall they trade in.
     *
     * The store directory and the feed share this version because a store change moves BOTH: the
     * directory listing, and the store block embedded on every one of that retailer's offer cards.
     *
     * A `Tenant` is SHARED (no `asset_id` of its own), so "which caches does this invalidate" has
     * to be answered by asking where they trade — through the `lease_unit` pivot, so a mall they
     * occupy only as an additional unit is included. A chain in three malls bumps three versions
     * and leaves every other property's cache alone.
     *
     * **Known gap, and why it is acceptable:** replacing a store LOGO is a medialibrary write, not
     * a `Tenant` save, so it does not reach this method. The TTL is what covers it — a new logo
     * appears within {@see TTL_SECONDS}. Hooking medialibrary's own events for one field would add
     * a second, subtler invalidation path for a change nobody makes twice a year.
     */
    public static function bumpForTenant(int $tenantId): void
    {
        $assetIds = \App\Models\Unit::query()
            ->whereHas('allLeases', fn ($lease) => $lease
                ->where('leases.tenant_id', $tenantId)
                ->where('leases.status', 'active'))
            ->distinct()
            ->pluck('asset_id');

        foreach ($assetIds as $assetId) {
            self::bump((int) $assetId);
        }
    }

    private static function key(int $assetId): string
    {
        return "marketing-feed-version:{$assetId}";
    }
}
