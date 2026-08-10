<?php

namespace App\Http\Controllers\Api\V1\PublicFeed;

use App\Models\MarketingPost;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/public/malls/{code}/posts/{post}/click — the shopper tapped the call to action.
 *
 * **These counters are indicative, not audited, and the module doc says so.** An unauthenticated
 * endpoint can be called in a loop by anyone, and no amount of throttling changes that — it only
 * changes how long inflating the number takes. The honest options were: don't measure engagement
 * at all, or measure it and be clear about what the measurement is worth. A mall marketer
 * comparing two campaigns run under the same conditions gets real signal from these; anyone
 * treating them as billable impressions is misusing them.
 *
 * The tight rate limit on the route is therefore not a correctness guarantee — it is there so a
 * loop costs the database rather than being free.
 */
class RecordPostClickController extends PublicFeedController
{
    public function __invoke(string $code, int $post): JsonResponse
    {
        $mall = $this->resolveMall($code);

        // Only a post that is actually on the feed can be clicked — the same visibility rule the
        // detail screen uses, so a stale id cannot keep incrementing a retired campaign.
        $exists = MarketingPost::query()
            ->where('asset_id', $mall->id)
            ->whereKey($post)
            ->liveFor(MarketingPost::AUDIENCE_VISITORS)
            ->exists();

        abort_unless($exists, 404);

        // Builder increment, not a model save — see ShowPublicPostController for why.
        MarketingPost::query()->whereKey($post)->increment('click_count');

        return $this->ok(null, null, 204);
    }
}
