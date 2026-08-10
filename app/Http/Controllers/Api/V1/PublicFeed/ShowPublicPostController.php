<?php

namespace App\Http\Controllers\Api\V1\PublicFeed;

use App\Http\Resources\Api\V1\PublicFeed\PublicMarketingPostResource;
use App\Models\MarketingPost;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/public/malls/{code}/posts/{post} — one offer's detail screen.
 *
 * Scoped to the mall in the URL as well as the id, so a post id from another property 404s rather
 * than rendering under the wrong mall's branding. The `liveFor()` filter is applied here too and
 * not only in the list: an id that has left the feed must stop resolving, or every expired offer
 * stays reachable forever by anyone who deep-linked it.
 */
class ShowPublicPostController extends PublicFeedController
{
    public function __invoke(string $code, int $post): JsonResponse
    {
        $mall = $this->resolveMall($code);

        $record = MarketingPost::query()
            ->where('asset_id', $mall->id)
            ->whereKey($post)
            ->liveFor(MarketingPost::AUDIENCE_VISITORS)
            ->with(['tenant.media', 'media'])
            ->first();

        abort_if($record === null, 404);

        // Count the read. Deliberately a builder increment, NOT $record->increment(): a model save
        // would fire the HasSearchText hook and re-fold the search blob on every shopper view —
        // thousands of pointless writes to a column no counter feeds. Safe as a mass update here
        // precisely because view_count is not one of searchTextSources().
        MarketingPost::query()->whereKey($record->getKey())->increment('view_count');

        return $this->ok(new PublicMarketingPostResource($record));
    }
}
