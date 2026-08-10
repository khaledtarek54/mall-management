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

    private static function key(int $assetId): string
    {
        return "marketing-feed-version:{$assetId}";
    }
}
