<?php

namespace App\Actions\Api\V1\Requests;

use App\Models\TenantRequest;
use App\Models\TenantUser;
use App\Services\TenantRequestService;

/**
 * The tenant accepts that their request is actually resolved, closing it.
 *
 * The confirmable rule (`resolved` only) lives in the service so it cannot drift between the mobile
 * API and the web portal — the two are the same surface with different renderers.
 */
class ConfirmTenantRequestAction
{
    public function __construct(private TenantRequestService $service) {}

    public function handle(TenantRequest $request, ?TenantUser $by = null): TenantRequest
    {
        return $this->service->confirmResolution($request, $by);
    }
}
