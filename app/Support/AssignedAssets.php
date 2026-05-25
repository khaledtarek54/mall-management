<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Property-staff query scoping helper.
 *
 * Resources call AssignedAssets::idsForCurrentUser() in their getEloquentQuery()
 * to filter to only the assets the user is assigned to (via the `asset_user`
 * pivot). Returns NULL when scoping should not apply — meaning "show everything".
 *
 * Policy:
 *  - No authenticated user      → null (treat as system; don't scope)
 *  - super_admin                → null (platform admin sees everything)
 *  - User with 0 assigned assets → null (back-compat for single-mall deployments
 *                                  where assignments aren't used yet)
 *  - User with N assigned assets → array of N asset IDs
 *
 * Composes cleanly with CurrentOperatorScope (per-operator filtering) — both
 * layers apply independently to Asset rows.
 */
class AssignedAssets
{
    /**
     * Asset IDs visible to the currently authenticated user, or null if no
     * scoping should apply.
     *
     * @return int[]|null
     */
    public static function idsForCurrentUser(): ?array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return null;
        }

        return static::idsFor($user);
    }

    /**
     * Asset IDs visible to a given user, or null if no scoping should apply.
     *
     * @return int[]|null
     */
    public static function idsFor(User $user): ?array
    {
        // Platform admin sees everything.
        if ($user->hasRole('super_admin')) {
            return null;
        }

        $ids = $user->assignedAssets()
            ->withoutGlobalScopes()
            ->pluck('assets.id')
            ->all();

        // No assignments → unrestricted (back-compat; admins haven't configured
        // staff-property assignments yet, so show everything).
        if (empty($ids)) {
            return null;
        }

        return array_map('intval', $ids);
    }

    /**
     * True when the current user has at least one assigned asset AND is not
     * super_admin. Useful for showing UI hints ("Showing data for: Haya Walk").
     */
    public static function isRestricted(?User $user = null): bool
    {
        $user ??= Auth::user();
        if (! $user instanceof User) {
            return false;
        }
        return static::idsFor($user) !== null;
    }
}
