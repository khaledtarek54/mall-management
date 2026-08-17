<?php

namespace App\Actions\Api\V1\Requests;

use App\Models\Tenant;
use App\Models\TenantRequest;
use App\Services\TenantRequestService;
use Illuminate\Http\UploadedFile;

/**
 * Submit a maintenance request from the mobile app.
 *
 * The heavy lifting (reference generation, unit/lease resolution from the
 * active lease, SLA target, staff fan-out) already lives in the shared
 * TenantRequestService used by the web portal — this action is the API
 * seam that delegates to it, so mobile and portal submissions stay identical.
 */
class CreateTenantRequestAction
{
    public function __construct(private TenantRequestService $service) {}

    /**
     * @param  array<string,mixed>  $data
     * @param  array<int, UploadedFile>  $attachments  Photos / PDFs of the issue.
     */
    public function handle(Tenant $tenant, array $data, array $attachments = []): TenantRequest
    {
        $request = $this->service->create($data, $tenant);

        // Push uploaded files into the same `attachments` collection the portal
        // uses, so app- and admin-sourced attachments are indistinguishable
        // downstream. Done outside the service's create transaction because
        // media moves files on disk and the portal handles its own upload.
        foreach ($attachments as $file) {
            $request->addMedia($file)->toMediaCollection('attachments');
        }

        return $request->load('unit', 'media');
    }
}
