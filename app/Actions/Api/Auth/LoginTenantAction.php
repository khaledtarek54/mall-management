<?php

namespace App\Actions\Api\Auth;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

/**
 * Authenticate a tenant by email + password and issue a Sanctum personal
 * access token bound to their device.
 *
 * Failure modes follow the mobile contract's status codes:
 *  - wrong email / password → 401
 *  - account not active (blocked) → 403 (drives the app's Blocked screen)
 *
 * Returns the tenant, the plain-text token, and the tenant's leases (the
 * login screen lists them so the user can pick one).
 */
class LoginTenantAction
{
    /**
     * @return array{tenant: Tenant, token: NewAccessToken, leases: Collection}
     */
    public function handle(string $email, string $password, string $deviceName): array
    {
        $tenant = Tenant::query()
            ->where('email', $email)
            ->first();

        if (! $tenant || ! Hash::check($password, $tenant->password)) {
            abort(401, __('auth.failed'));
        }

        if ($tenant->status !== 'active') {
            abort(403, __('auth.account_blocked'));
        }

        // Revoke any prior token issued to the same device name so the user
        // can log in cleanly from a phone they've signed out of before.
        $tenant->tokens()->where('name', $deviceName)->delete();

        $token = $tenant->createToken(name: $deviceName, abilities: ['tenant:*']);

        // All leases (active + historical) with unit/asset context. Share the
        // already-loaded tenant onto each so the resource doesn't re-query.
        $leases = $tenant->leases()->with('unit.asset')->get()
            ->each->setRelation('tenant', $tenant);

        return [
            'tenant' => $tenant,
            'token' => $token,
            'leases' => $leases,
        ];
    }
}
