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
        $leases = $request->user()
            ->activeLeases()
            ->with('unit.asset')
            ->get();

        return LeaseResource::collection($leases);
    }
}
