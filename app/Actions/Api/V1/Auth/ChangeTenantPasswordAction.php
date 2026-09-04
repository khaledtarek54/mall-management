<?php

namespace App\Actions\Api\V1\Auth;

use App\Models\TenantUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Authenticated password change: verify the current password, then set the
 * new one. The TenantUser model's `password` cast hashes on assignment.
 *
 * Revokes every OTHER token afterwards so a stolen session elsewhere is
 * invalidated, while leaving the caller's current token intact.
 */
class ChangeTenantPasswordAction
{
    public function handle(TenantUser $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('api.password_current_incorrect')],
            ]);
        }

        $user->update(['password' => $newPassword]);

        $currentTokenId = $user->currentAccessToken()?->id;
        $user->tokens()
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();
    }
}
