<?php

namespace App\Http\Controllers\Api\V1\Maintenance;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\MaintenanceRequestResource;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me/maintenance-requests/{id} — detail with the public comment
 * thread. Internal staff notes (is_internal = true) are never exposed.
 */
class ShowMaintenanceRequestController extends ApiController
{
    public function __invoke(Request $request, int $id): MaintenanceRequestResource
    {
        $maintenanceRequest = $request->user()->maintenanceRequests()
            ->with([
                'unit',
                'comments' => fn ($q) => $q->where('is_internal', false),
                'comments.author',
            ])
            ->findOrFail($id);

        return new MaintenanceRequestResource($maintenanceRequest);
    }
}
