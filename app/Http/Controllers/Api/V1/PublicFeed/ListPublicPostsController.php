<?php

namespace App\Http\Controllers\Api\V1\PublicFeed;

use App\Http\Resources\Api\V1\PublicFeed\PublicMarketingPostResource;
use App\Models\MarketingPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * GET /api/v1/public/malls/{code}/posts — the shopper feed for one mall.
 *
 * The carousel and the list are the SAME query, ordered by `feedOrder()` (featured first, then the
 * marketing team's priority, then newest). A client renders the leading `is_featured` run as its
 * carousel and the remainder as the list, so the two can never disagree about what is "top" —
 * which is what a separate /carousel endpoint would eventually let happen.
 *
 * Visibility is `MarketingPost::liveFor('visitors')` and nothing else. That predicate lives on the
 * model precisely so this controller cannot invent its own version of "currently showing".
 *
 * **Cached for 60 seconds.** This is the one endpoint every shopper hits on app open, and it is
 * identical for all of them — there is no per-user content to personalise. Sixty seconds is short
 * enough that an operator publishing an offer sees it within a minute (they will refresh, and
 * they will), and long enough to flatten the open-the-app spike. TTL-only invalidation is
 * deliberate: an event-based bust would need hooks on publish, archive, edit, media change and the
 * expiry sweep, and the one that gets forgotten serves a stale feed indefinitely.
 */
class ListPublicPostsController extends PublicFeedController
{
    public function __invoke(Request $request, string $code): JsonResponse
    {
        $mall = $this->resolveMall($code);

        $type = $request->query('type');
        $type = in_array($type, MarketingPost::TYPES, true) ? $type : null;
        $featuredOnly = $request->boolean('featured');
        $perPage = $this->perPage($request);
        $page = max(1, (int) $request->integer('page', 1));

        $key = sprintf(
            'public-feed:%d:%s:%d:%d:%d',
            $mall->id, $type ?? 'all', $featuredOnly ? 1 : 0, $perPage, $page
        );

        $payload = Cache::remember($key, self::CACHE_SECONDS, function () use ($mall, $type, $featuredOnly, $perPage) {
            $query = MarketingPost::query()
                ->where('asset_id', $mall->id)
                ->liveFor(MarketingPost::AUDIENCE_VISITORS)
                // The store behind each card — eager-loaded because every card renders its logo,
                // and the media relation too, or heroUrl() is a query per row.
                ->with(['tenant.media', 'media'])
                ->feedOrder();

            if ($type !== null) {
                $query->where('type', $type);
            }

            if ($featuredOnly) {
                $query->where('is_featured', true);
            }

            $posts = $query->paginate($perPage);

            return [
                'data' => PublicMarketingPostResource::collection($posts)->resolve(),
                'meta' => [
                    'current_page' => $posts->currentPage(),
                    'last_page' => $posts->lastPage(),
                    'per_page' => $posts->perPage(),
                    'total' => $posts->total(),
                ],
            ];
        });

        return response()->json($payload);
    }
}
