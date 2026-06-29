<?php

namespace App\Actions\Api\V1\Maintenance;

use App\Models\TenantRequest;
use App\Services\TenantRequestService;

/**
 * Tenant submits a close-out satisfaction rating (CSAT) for their request.
 * The rateable rule (resolved/closed only) lives in the service so it can't
 * drift between the mobile API and the web portal.
 */
class RateMaintenanceRequestAction
{
    public function __construct(private TenantRequestService $service) {}

    public function handle(TenantRequest $request, int $rating, ?string $comment = null): TenantRequest
    {
        return $this->service->rate($request, $rating, $comment);
    }
}
