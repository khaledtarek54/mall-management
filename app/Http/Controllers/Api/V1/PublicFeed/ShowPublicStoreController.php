<?php

namespace App\Http\Controllers\Api\V1\PublicFeed;

use App\Http\Resources\Api\V1\PublicFeed\PublicMarketingPostResource;
use App\Http\Resources\Api\V1\PublicFeed\PublicStoreResource;
use App\Models\MarketingPost;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/public/malls/{code}/stores/{store} — one shop's page, with everything it is
 * currently running.
 *
 * The retailer must be listed, active, AND trading in the mall named in the URL. All three are
 * required for a 404 rather than a partial answer: resolving a store the shopper cannot walk to
 * would let the endpoint enumerate the operator's whole tenant roster from any mall's URL.
 */
class ShowPublicStoreController extends PublicFeedController
{
    public function __invoke(string $code, int $store): JsonResponse
    {
        $mall = $this->resolveMall($code);

        $tenant = Tenant::query()
            ->whereKey($store)
            ->where('is_listed', true)
            ->where('status', 'active')
            ->whereHas('activeLeases.units', fn ($q) => $q->where('units.asset_id', $mall->id))
            ->with(['media', 'activeLeases.units'])
            ->first();

        abort_if($tenant === null, 404);

        $tenant->public_locations = self::locationsFor($tenant, $mall->id);

        // This store's live cards in THIS mall — same predicate and same order as the main feed.
        $posts = MarketingPost::query()
            ->where('asset_id', $mall->id)
            ->where('tenant_id', $tenant->getKey())
            ->liveFor(MarketingPost::AUDIENCE_VISITORS)
            ->with('media')
            ->feedOrder()
            ->get();

        return $this->ok([
            'store' => (new PublicStoreResource($tenant))->resolve(),
            'posts' => PublicMarketingPostResource::collection($posts)->resolve(),
        ]);
    }
}
