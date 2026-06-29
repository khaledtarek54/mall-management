<?php

namespace App\Actions\Api\V1\Devices;

use App\Models\DeviceToken;
use App\Models\Tenant;

/**
 * Register (or refresh) a push token for one device. Upserts on
 * (tenant, platform, device_name) so repeated logins from the same phone
 * replace the token rather than stacking stale rows.
 */
class RegisterDeviceTokenAction
{
    /**
     * @param  array<string,mixed>  $data  Keys: platform, token, device_name?
     */
    public function handle(Tenant $tenant, array $data): DeviceToken
    {
        // A physical device's push token maps to exactly ONE tenant. If another
        // tenant previously registered this exact token (a shared / handed-over
        // phone), drop their stale row first so pushes can't leak across tenants.
        DeviceToken::where('token', $data['token'])
            ->where('tenant_id', '!=', $tenant->id)
            ->delete();

        return DeviceToken::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'platform' => $data['platform'],
                'device_name' => $data['device_name'] ?? null,
            ],
            [
                'token' => $data['token'],
                'last_used_at' => now(),
            ],
        );
    }
}
