<?php

namespace App\Actions\Api\V1\Requests;

use App\Models\TenantRequest;
use App\Models\TenantRequestComment;
use App\Models\Tenant;
use App\Services\TenantRequestService;

/**
 * Add a tenant comment to a maintenance request. is_internal is forced false
 * here — the API can never create a staff-only internal note.
 */
class AddTenantRequestCommentAction
{
    public function __construct(private TenantRequestService $service) {}

    public function handle(TenantRequest $request, Tenant $tenant, string $body): TenantRequestComment
    {
        return $this->service->comment($request, $tenant, $body, isInternal: false);
    }
}
