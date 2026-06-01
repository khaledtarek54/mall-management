<?php

namespace App\Actions\Api\V1\Profile;

use App\Models\Tenant;

/**
 * Apply a tenant's self-service profile edit. The caller (FormRequest) has
 * already restricted the payload to editable contact fields, so this action
 * only persists and returns the fresh model.
 */
class UpdateTenantProfileAction
{
    /**
     * @param  array<string,mixed>  $data
     */
    public function handle(Tenant $tenant, array $data): Tenant
    {
        $tenant->update($data);

        return $tenant->refresh();
    }
}
