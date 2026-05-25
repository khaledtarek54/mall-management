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

        if ($assetId = TenantScope::currentAssetId()) {
            $query->whereHas(
                static::tenantScopeRelation(),
                fn (Builder $q) => $q->where('asset_id', $assetId),
            );
        }

        return $query;
    }
}
