<?php

namespace App\Support;

use App\Models\Asset;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for "which property are we filtering to?"
 *
 * Used by widgets and any code that needs to scope queries to the current
 * Filament tenant. Returns null when the user picked "All Properties" or
 * when no tenant context is set (e.g. CLI, console commands), meaning
 * "do not scope".
 */
class TenantScope
{
    /**
     * Current tenant's asset ID, or null when scoping should not apply
     * (no tenant set, or the "All Properties" pseudo-tenant is active).
     */
    public static function currentAssetId(): ?int
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Asset) {
            return null;
        }

        if ($tenant->isAllProperties()) {
            return null;
        }

        return (int) $tenant->getKey();
    }

    /**
     * Apply tenant scoping to a query in one call. Used by widgets and
     * services that need to constrain a Model query to the current
     * property — passes through unchanged when "All Properties" is
     * active or no tenant is set.
     *
     *   $invoices = TenantScope::applyTo(Invoice::query());   // invoices carry their own asset_id
     *
     * Pass `null` (or omit) for `$relation` when the model itself has
     * `asset_id` directly (Unit, UtilityMeter, CamExpensePool).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function applyTo(
        Builder $query,
        ?string $relation = null,
    ): Builder {
        $assetId = self::currentAssetId();
        if ($assetId !== null) {
            return $relation === null
                ? $query->where('asset_id', $assetId)
                : $query->whereHas($relation, fn ($q) => $q->where('asset_id', $assetId));
        }

        // "All Properties" (or no tenant): a restricted user must still be pinned
        // to their assigned set — only super_admin / unconstrained gets null here
        // (genuinely portfolio-wide). Without this, All-mode leaked every property
        // to restricted users in widgets/services.
        $ids = self::visibleAssetIds();
        if ($ids === null) {
            return $query;
        }

        return $relation === null
            ? $query->whereIn('asset_id', $ids)
            : $query->whereHas($relation, fn ($q) => $q->whereIn('asset_id', $ids));
    }

    /**
     * When "All Properties" is active and the user has restricted access
     * (not super_admin), the queries still need to be constrained to the
     * user's assigned properties. Returns the list of asset IDs the
     * current user can see, or null if no constraint should apply
     * (super_admin viewing All, or single-property fallback).
     *
     * @return int[]|null
     */
    public static function visibleAssetIds(): ?array
    {
        $tenant = Filament::getTenant();

        // No tenant: defer to AssignedAssets — covers CLI + edge cases.
        if (! $tenant instanceof Asset) {
            return AssignedAssets::idsForCurrentUser();
        }

        // Single property — caller should use currentAssetId() instead;
        // returning [id] still works for whereIn-based filters.
        if (! $tenant->isAllProperties()) {
            return [(int) $tenant->getKey()];
        }

        // "All Properties" mode: super_admin sees all, others see only
        // their assigned set.
        return AssignedAssets::idsForCurrentUser();
    }

    /**
     * Resolve the asset-id filter for a ledger/finance report from a user-chosen
     * property id, clamped to what the current user may see. Returns:
     *   - null            → portfolio-wide (only for unrestricted users; honors a
     *                        specific pick as [id])
     *   - [id]            → the picked property, when the user may see it
     *   - visible set     → consolidated within the user's allowed properties, or
     *                        a safe fallback when the pick is outside their set
     *
     * This prevents a property-scoped user from reading another property's books
     * by tampering with the client-bound selection.
     *
     * @return array<int>|null
     */
    public static function reportAssetIds(?int $selected): ?array
    {
        $visible = self::visibleAssetIds();

        if ($visible === null) {
            // Unrestricted (super_admin / portfolio roles): honor the pick; null = all.
            return $selected !== null ? [$selected] : null;
        }

        if ($selected !== null && in_array($selected, $visible, true)) {
            return [$selected];
        }

        // No pick, or a pick outside the allowed set → restrict to the allowed set.
        return $visible;
    }

    /**
     * Clamp a single client-supplied asset id to what the current user may see.
     * The single-value sibling of reportAssetIds(): the id when it is visible,
     * null when it is blank or outside the user's set (null = unrestricted, per
     * visibleAssetIds()'s contract).
     *
     * **Use this for any query keyed on a form's `asset_id`.** That value is
     * client-supplied — the property Select is enabled in All-Properties mode — so a
     * crafted Livewire request can point it anywhere. Two traps make the raw value
     * unsafe even on forms that guard the write:
     *
     *  - Option lists render on `->live()` state, long before `assertAssetInScope()`
     *    runs at save — a picker keyed on the raw value enumerates an invisible
     *    property's records regardless of the refusal that follows.
     *  - `Rule::unique` is worse: Laravel runs every field rule in ONE pass *before*
     *    any mutate hook, and the rule compiles to a raw query that Filament's tenancy
     *    global scope never touches. Keyed raw it answers "is this code taken in
     *    <property>?" — the write is refused either way, but the field erroring (or
     *    not) is a one-bit existence oracle over another property's natural keys.
     *
     * Returning null collapses both: no options, and a unique rule that matches nothing.
     * `UniqueRuleScopeConformanceTest` fails CI if any Filament form keys a unique rule on
     * a raw client-supplied scope. See also clampLeaseId() and Portal::clampLeaseId().
     */
    public static function clampAssetId(mixed $assetId): ?int
    {
        if (blank($assetId)) {
            return null;
        }

        $visible = self::visibleAssetIds();

        if ($visible !== null && ! in_array((int) $assetId, $visible, true)) {
            return null;
        }

        return (int) $assetId;
    }

    /**
     * Clamp a client-supplied unit id to the properties the current user may see.
     * Returns null when blank or out of scope.
     *
     * Same contract and reason as clampAssetId(). Note this class of leak is not only
     * `Rule::unique`: any validation that *queries* on a client value leaks through its
     * pass/fail — e.g. "does this unit already have an active lease?" answers "is that
     * unit occupied?" for a property the user cannot see.
     */
    public static function clampUnitId(mixed $unitId): ?int
    {
        if (blank($unitId)) {
            return null;
        }

        $visible = self::visibleAssetIds();

        if ($visible === null) {
            return (int) $unitId;
        }

        return Unit::whereKey($unitId)->whereIn('asset_id', $visible)->exists()
            ? (int) $unitId
            : null;
    }

    /**
     * Clamp a client-supplied lease id to the properties the current user may see.
     * The lease's property is resolved through its unit (the chain PropertyIsolation
     * registers for Lease). Returns null when blank or out of scope.
     *
     * Same contract and same reason as clampAssetId() — a form's `lease_id` is
     * client-supplied, and `assertLeaseAssetInScope()` runs in a mutate hook, i.e. AFTER
     * the validation pass. A unique rule keyed on the raw lease id therefore leaks
     * whether a lease exists in an invisible property, and what it has already declared.
     */
    public static function clampLeaseId(mixed $leaseId): ?int
    {
        if (blank($leaseId)) {
            return null;
        }

        $visible = self::visibleAssetIds();

        if ($visible === null) {
            return (int) $leaseId;
        }

        $inScope = Lease::whereKey($leaseId)
            ->whereHas('unit', fn ($q) => $q->whereIn('asset_id', $visible))
            ->exists();

        return $inScope ? (int) $leaseId : null;
    }

    /**
     * Property-picker options (id => name) scoped to what the current user
     * may select: the current property, their assigned set in All-Properties
     * mode, or every real property for super_admin. Always excludes the
     * synthetic "All Properties" pseudo-asset.
     *
     * @return array<int, string>
     */
    public static function selectableAssetOptions(): array
    {
        $ids = self::visibleAssetIds();

        $query = Asset::query()->where('code', '!=', Asset::ALL_PROPERTIES_CODE);
        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        return $query->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Tenant-picker options (id => name) scoped to the current user's
     * visible properties via leases -> unit -> asset_id. Unconstrained for
     * super_admin in All-Properties mode.
     *
     * @return array<int, string>
     */
    public static function selectableTenantOptions(): array
    {
        $ids = self::visibleAssetIds();

        return Tenant::query()
            ->when($ids !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('leases.unit', fn ($u) => $u->whereIn('asset_id', (array) $ids))
                // Unaffiliated tenants (no lease yet) belong to no property,
                // so they're safe to offer everywhere — not a cross-property leak.
                ->orWhereDoesntHave('leases')))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
