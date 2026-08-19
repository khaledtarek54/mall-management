<?php

namespace App\Http\Controllers\Api\V1\PublicFeed;

use App\Models\Asset;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/public/malls — the malls a visitor app can show.
 *
 * How the app bootstraps: it has to turn "which building am I in" into the `code` every other
 * public route takes. Without this the code would have to be hard-coded into the client, and a
 * second mall would mean a store release.
 *
 * The `ALL` pseudo-asset is excluded here as well as in {@see PublicFeedController::resolveMall()}
 * — belt and braces, because a UI listing "All Properties" as a mall is a support ticket even
 * though the feed route would have refused it.
 *
 * Fields are an allowlist, like everything else on this surface: a mall's name, code, city and
 * logo are on its own website. Its area, ownership, currency and activity are not.
 */
class ListPublicMallsController extends PublicFeedController
{
    public function __invoke(): JsonResponse
    {
        $malls = Asset::query()
            ->where('is_active', true)
            // Publication is a SEPARATE decision from operation. Deactivating a mall to keep it
            // out of the shopper feed also empties the property switcher and hides its units, so
            // before this flag existed the only way to withhold a property was to break it.
            ->where('is_publicly_listed', true)
            ->where('code', '!=', Asset::ALL_PROPERTIES_CODE)
            ->with('media')
            ->orderBy('name')
            ->get()
            ->map(fn (Asset $asset) => [
                'code' => (string) $asset->code,
                'name' => (string) $asset->name,
                'city' => $asset->city,
                'logo_url' => $asset->getFirstMedia('logo')?->getFullUrl(),
            ])
            ->values()
            ->all();

        return response()->json(['data' => $malls]);
    }
}
