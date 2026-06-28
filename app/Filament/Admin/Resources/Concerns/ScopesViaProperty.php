<?php

namespace App\Filament\Admin\Resources\Concerns;

use App\Support\TenantScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared tenant-scoping behaviour for indirect resources — models that
 * don't carry an `asset_id` FK directly but reach an Asset through a
 * relationship chain (Lease via unit, Invoice via lease.unit, Payment
 * via invoices.lease.unit, etc).
 *
 * Resources must declare `tenantScopeRelation()` returning the chain
 * ending at a model with `asset_id`. We `whereHas` that chain against
 * the active tenant. The trait composes `BypassesFilamentTenantAutoScope`
 * for the no-op scope + soft-delete route binding shared with
 * CreditNoteResource.
 */
trait ScopesViaProperty
{
    use BypassesFilamentTenantAutoScope;

    abstract protected static function tenantScopeRelation(): string;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $relation = static::tenantScopeRelation();

        if ($assetId = TenantScope::currentAssetId()) {
            $query->whereHas($relation, fn (Builder $q) => $q->where('asset_id', $assetId));
        } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
            // "All Properties" for a RESTRICTED user — pin to their assigned set
            // (super_admin / unconstrained returns null = portfolio-wide). Without
            // this, All-mode leaked every property's records to restricted roles.
            $query->whereHas($relation, fn (Builder $q) => $q->whereIn('asset_id', $ids));
        }

        return $query;
    }
}
