<?php

namespace App\Support;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

        $code = Asset::ALL_PROPERTIES_CODE;

        // Staff assignments (asset_user) ∪ legal ownership (asset_owner) — so
        // Jawad owners are scoped to their owned properties, not unrestricted.
        // Ownership is CURRENT-tenure only (currentOwnedAssets, not the all-time ownedAssets): a
        // former owner who sold their stake (asset_owner.ended_at in the past) must lose visibility to
        // the sold property, and a not-yet-started owner must not see it early — matching the owner
        // statements + the owner-panel-era widget. (Staff assignments stay all-time — that tenure is
        // a separate concern.)
        $assigned = $user->assignedAssets()->where('assets.code', '!=', $code)->pluck('assets.id')->all();
        $owned = $user->currentOwnedAssets()->where('assets.code', '!=', $code)->pluck('assets.id')->all();
        $ids = array_values(array_unique(array_merge($assigned, $owned)));

        if (empty($ids)) {
            // Distinguish "never scoped" from "scope has LAPSED":
            //  - a user who was never an owner/staff → null = unrestricted (the single-mall back-compat
            //    for deployments with no explicit assignments).
            //  - a former/not-yet-started owner (or ex-staff) who holds NOTHING now must see NOTHING,
            //    not everything. Returning [] would be unsafe: Laravel's `->when([], …)` treats an
            //    empty array as falsy and SKIPS the filter (= unrestricted), and several scoping call
            //    sites use that form. So return a never-matching sentinel [0] (asset ids are ≥ 1):
            //    truthy → `->when()` still applies the restriction, non-null → `!== null` guards apply,
            //    and `whereIn('asset_id', [0])` matches nothing. Fail-closed for every consumer.
            //
            // The probe reads the PIVOT ROWS, not assignedAssets()/ownedAssets().
            // Those are relations to a SOFT-DELETING model, so archiving a property
            // made them return nothing — and a user assigned to exactly that
            // property fell into the "never scoped" branch and became UNRESTRICTED,
            // gaining read access to every other property. Archiving a mall is an
            // ordinary super_admin action, so that was reachable, not theoretical.
            // "Was this user ever scoped?" is a question about the assignment, and
            // must not depend on whether the asset still exists.
            // Regression: tests/Feature/Regression/AssignedAssetsLapsedScopeTest.php
            $everScoped = $user->hasRole('owner')
                || DB::table('asset_owner')->where('user_id', $user->getKey())->exists()
                || DB::table('asset_user')->where('user_id', $user->getKey())->exists();

            return $everScoped ? [0] : null;
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
