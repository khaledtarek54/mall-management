<?php

namespace App\Actions\Api\Auth;

use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Authenticate a tenant by email + password and issue a Sanctum personal
 * access token bound to their device.
 *
 * Best-practice notes:
 *  - Validation lives in LoginRequest, not here. This action assumes the
 *    inputs are well-formed strings.
 *  - All failure modes are exceptions so the controller is uniform.
 *  - The token returned is the plain-text token (only available at creation).
 *    The caller is responsible for handing it to the client and never
 *    persisting the plain string anywhere server-side.
 */
class LoginTenantAction
{
    /**
     * @return array{tenant: Tenant, token: NewAccessToken}
     */
    public function handle(string $email, string $password, string $deviceName): array
    {
        $tenant = Tenant::query()
            ->where('email', $email)
            ->first();

        if (! $tenant || ! Hash::check($password, $tenant->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if ($tenant->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => [__('auth.account_inactive')],
            ]);
        }

        // Revoke any prior token issued to the same device name so the user
        // can log in cleanly from a phone they've signed out of before.
        $tenant->tokens()->where('name', $deviceName)->delete();

        $token = $tenant->createToken(
            name: $deviceName,
            abilities: ['tenant:*'],
        );

        return [
            'tenant' => $tenant,
            'token' => $token,
        ];
    }
}
