<?php

namespace App\Http\Controllers\Api\V1\Maintenance;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\MaintenanceRequestResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/me/maintenance-requests — paginated list, newest first.
 */
class ListMaintenanceRequestsController extends ApiController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $query = $request->user()->maintenanceRequests()
            ->with('unit')
            ->latest('submitted_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return MaintenanceRequestResource::collection($query->paginate($this->perPage($request)));
    }
}
