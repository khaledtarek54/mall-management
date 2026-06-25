<?php

namespace App\Support;

use App\Models\Asset;
use App\Models\Tenant;
use Filament\Facades\Filament;

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
     *   $invoices = TenantScope::applyTo(Invoice::query(), 'lease.unit');
     *
     * Pass `null` (or omit) for `$relation` when the model itself has
     * `asset_id` directly (Unit, UtilityMeter, CamExpensePool).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public static function applyTo(
        \Illuminate\Database\Eloquent\Builder $query,
        ?string $relation = null,
    ): \Illuminate\Database\Eloquent\Builder {
        $assetId = self::currentAssetId();
        if ($assetId === null) {
            return $query;
        }

        if ($relation === null) {
            return $query->where('asset_id', $assetId);
        }

        return $query->whereHas(
            $relation,
            fn ($q) => $q->where('asset_id', $assetId),
        );
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
