<?php

namespace App\Actions\Api\V1\Devices;

use App\Models\Tenant;

/**
 * Remove a device's push registration. Scoped to the tenant's own tokens so
 * one tenant can never delete another's device. Returns whether a row was
 * actually removed (false → unknown id, surfaced as 404 by the controller).
 */
class UnregisterDeviceTokenAction
{
    public function handle(Tenant $tenant, int $deviceTokenId): bool
    {
        return (bool) $tenant->deviceTokens()
            ->whereKey($deviceTokenId)
            ->delete();
    }
}
