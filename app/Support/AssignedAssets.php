<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Property-staff query scoping helper.
 *
 * Returns the list of asset IDs the current user can see, or null when no
 * scoping should apply (super_admin, no auth, or a user with no
 * assignments — back-compat for single-mall deployments).
 *
 * Most of the platform now scopes via Filament's per-property tenancy —
 * the URL slug carries the active property and queries filter through
 * App\Support\TenantScope::currentAssetId(). This helper remains useful
 * only at boundaries that sit ABOVE the tenancy layer: the AssetResource
 * (which lists properties themselves) and the "All Properties" pseudo-
 * tenant view (where we still need to constrain non-super-admin users
 * to their assigned set).
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

        $code = \App\Models\Asset::ALL_PROPERTIES_CODE;

        // Staff assignments (asset_user) ∪ legal ownership (asset_owner) — so
        // Jawad owners are scoped to their owned properties, not unrestricted.
        $assigned = $user->assignedAssets()->where('assets.code', '!=', $code)->pluck('assets.id')->all();
        $owned = $user->ownedAssets()->where('assets.code', '!=', $code)->pluck('assets.id')->all();
        $ids = array_values(array_unique(array_merge($assigned, $owned)));

        // No assignments and no ownership → unrestricted (back-compat;
        // single-mall deployments with no explicit assignments show everything).
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
