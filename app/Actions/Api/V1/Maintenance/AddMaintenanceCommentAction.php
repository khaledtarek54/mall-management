<?php

namespace App\Actions\Api\V1\Maintenance;

use App\Models\MaintenanceRequest;
use App\Models\MaintenanceRequestComment;
use App\Models\Tenant;
use App\Services\MaintenanceRequestService;

/**
 * Add a tenant comment to a maintenance request. is_internal is forced false
 * here — the API can never create a staff-only internal note.
 */
class AddMaintenanceCommentAction
{
    public function __construct(private MaintenanceRequestService $service) {}

    public function handle(MaintenanceRequest $request, Tenant $tenant, string $body): MaintenanceRequestComment
    {
        return $this->service->comment($request, $tenant, $body, isInternal: false);
    }
}
