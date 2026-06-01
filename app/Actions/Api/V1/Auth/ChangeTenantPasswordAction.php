<?php

namespace App\Actions\Api\V1\Auth;

use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Authenticated password change: verify the current password, then set the
 * new one. The Tenant model's `password` cast hashes on assignment.
 *
 * Revokes every OTHER token afterwards so a stolen session elsewhere is
 * invalidated, while leaving the caller's current token intact.
 */
class ChangeTenantPasswordAction
{
    public function handle(Tenant $tenant, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $tenant->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('api.password_current_incorrect')],
            ]);
        }

        $tenant->update(['password' => $newPassword]);

        $currentTokenId = $tenant->currentAccessToken()?->id;
        $tenant->tokens()
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();
    }
}
