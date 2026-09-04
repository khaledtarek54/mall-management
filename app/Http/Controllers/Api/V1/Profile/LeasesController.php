<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\LeaseResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/me/leases — the tenant's active leases (typically one). Includes
 * unit + asset context so the app can label screens ("Haya Walk · A-01").
 */
class LeasesController extends ApiController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $leases = $request->user()->tenant
            ->activeLeases()
            // `units` and `media` join the eager load because the resource answers three questions
            // off them that a released client never had: the FULL premises (a lease over two shops
            // showed one), the area the rent was actually priced on, and whether a signed lease
            // exists to download. `whenLoaded` means forgetting one silently omits the key rather
            // than issuing a query per lease.
            // `deposits` + `depositApplications` because `depositHeld()` prefers a loaded
            // relation and queries when there is none — the difference between one query for the
            // page and three per lease.
            ->with(['unit.asset', 'units.floor', 'rentableItems', 'media', 'deposits', 'depositApplications'])
            ->get();

        return LeaseResource::collection($leases);
    }
}
