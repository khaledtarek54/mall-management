<?php

namespace App\Actions\Api\V1\Requests;

use App\Models\TenantRequest;
use App\Models\TenantUser;
use App\Services\TenantRequestService;

/**
 * The tenant says it is not actually fixed, returning the request to the operator.
 *
 * Same service, same refusals as the portal — including the required reason, which is what stops an
 * engineer being sent back knowing no more than the first time.
 */
class DisputeTenantRequestAction
{
    public function __construct(private TenantRequestService $service) {}

    public function handle(TenantRequest $request, ?TenantUser $by, string $reason): TenantRequest
    {
        return $this->service->disputeResolution($request, $by, $reason);
    }
}
