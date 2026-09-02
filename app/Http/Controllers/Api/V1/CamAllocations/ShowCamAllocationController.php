<?php

namespace App\Http\Controllers\Api\V1\CamAllocations;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\CamAllocationResource;
use App\Models\CamAllocation;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me/cam-allocations/{id} — one year's share in full.
 *
 * Another party's allocation is a 404, never a 403 — the whole-surface convention against existence
 * enumeration.
 */
class ShowCamAllocationController extends ApiController
{
    public function __invoke(Request $request, int $id): CamAllocationResource
    {
        return new CamAllocationResource(
            CamAllocation::ownedBy($request->user())
                ->with(['pool.asset', 'lease.unit.floor', 'unitOwnership.unit.floor'])
                ->findOrFail($id),
        );
    }
}
