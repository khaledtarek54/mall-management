<?php

namespace App\Actions\Api\V1\Maintenance;

use App\Models\MaintenanceRequest;
use App\Models\Tenant;
use App\Services\MaintenanceRequestService;

/**
 * Submit a maintenance request from the mobile app.
 *
 * The heavy lifting (reference generation, unit/lease resolution from the
 * active lease, SLA target, staff fan-out) already lives in the shared
 * MaintenanceRequestService used by the web portal — this action is the API
 * seam that delegates to it, so mobile and portal submissions stay identical.
 */
class CreateMaintenanceRequestAction
{
    public function __construct(private MaintenanceRequestService $service) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public function handle(Tenant $tenant, array $data): MaintenanceRequest
    {
        return $this->service->create($data, $tenant)->load('unit');
    }
}
