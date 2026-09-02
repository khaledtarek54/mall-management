<?php

namespace App\Http\Controllers\Api\V1\CamAllocations;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\CamAllocationResource;
use App\Models\CamAllocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/me/cam-allocations — the tenant's share of each year's common-area cost.
 *
 * Newest service-charge year first, which is the tenant's own first question ("which year am I
 * looking at"). `?status=` filters (pending / billed / disputed / closed), `?period_year=` narrows
 * to one year.
 *
 * Scoped through `CamAllocation::ownedBy()` — the ONE predicate, shared with the detail endpoint,
 * the statement, and (in kind) the portal resource. Its OR branch is the whole point: an allocation
 * belongs to a lease **or** to a unit ownership, and scoping through `lease` alone returns NOTHING
 * for a unit owner, who is a CAM participant in his own right.
 */
class ListCamAllocationsController extends ApiController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $query = CamAllocation::query()
            ->with(['pool.asset', 'lease.unit.floor', 'unitOwnership.unit.floor'])
            ->ownedBy($request->user())
            ->orderByDesc('id');

        if ($status = $request->query('status')) {
            $query->where('cam_allocations.status', $status);
        }

        if ($year = $request->query('period_year')) {
            $query->whereHas('pool', fn ($p) => $p->where('period_year', (int) $year));
        }

        return CamAllocationResource::collection($query->paginate($this->perPage($request)));
    }
}
