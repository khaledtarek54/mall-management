<?php

namespace App\Http\Controllers\Api\V1\PublicFeed;

use App\Http\Resources\Api\V1\PublicFeed\PublicStoreResource;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * GET /api/v1/public/malls/{code}/stores — the shopper-facing store directory.
 *
 * "In this mall" is `activeLeases.units` — the `lease_unit` pivot, not `leases.unit_id`. The
 * master-unit shortcut would silently omit any retailer whose presence here is an ADDITIONAL unit
 * on a multi-unit lease, and a directory that quietly drops the mall's biggest anchor tenant is a
 * bug nobody would spot from the code. Same trap the announcement fan-out documents.
 *
 * `is_listed` is a display switch, not the security boundary. What actually keeps a retailer's
 * paperwork off the public internet is {@see PublicStoreResource}'s field allowlist — this filter
 * only decides who appears.
 */
class ListPublicStoresController extends PublicFeedController
{
    public function __invoke(Request $request, string $code): JsonResponse
    {
        $mall = $this->resolveMall($code);

        $category = $request->query('category');
        $category = in_array($category, Tenant::RETAIL_CATEGORIES, true) ? $category : null;

        $key = sprintf('public-stores:%d:%s', $mall->id, $category ?? 'all');

        $payload = Cache::remember($key, self::CACHE_SECONDS, function () use ($mall, $category) {
            $query = Tenant::query()
                ->where('is_listed', true)
                ->where('status', 'active')
                ->whereHas('activeLeases.units', fn ($q) => $q->where('units.asset_id', $mall->id))
                // `media` for the logo, `activeLeases.units` for the shop numbers — without the
                // second the location map below is a query per retailer.
                ->with(['media', 'activeLeases.units'])
                ->orderBy('name');

            if ($category !== null) {
                $query->where('retail_category', $category);
            }

            $stores = $query->get()->map(function (Tenant $tenant) use ($mall) {
                // Where to find it, in THIS mall only. Never the retailer's footprint across the
                // portfolio — see PublicStoreResource.
                $tenant->public_locations = $tenant->activeLeases
                    ->flatMap(fn ($lease) => $lease->units)
                    ->where('asset_id', $mall->id)
                    ->pluck('code')
                    ->unique()
                    ->values()
                    ->all();

                return $tenant;
            });

            return ['data' => PublicStoreResource::collection($stores)->resolve()];
        });

        return response()->json($payload);
    }
}
