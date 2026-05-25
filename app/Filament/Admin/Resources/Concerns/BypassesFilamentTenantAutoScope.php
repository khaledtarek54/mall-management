<?php

namespace App\Filament\Admin\Resources\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Minimal opt-out trait for resources that scope themselves manually in
 * `getEloquentQuery()` (because the model has no direct `asset_id` FK).
 * Gives:
 *
 *   - `scopeEloquentQueryToTenant` no-op — keeps Filament from looking
 *     up an `asset()` relationship that doesn't exist on the model.
 *   - `getRecordRouteBindingEloquentQuery` that bypasses SoftDeletingScope
 *     so a record link still resolves once the row is trashed.
 *
 * Use this directly on resources whose query needs special-case logic
 * (e.g. CreditNote's "standalone notes also visible" rule). For the
 * normal "scope by single relation chain" case, prefer
 * `ScopesViaProperty` which builds on top of this.
 */
trait BypassesFilamentTenantAutoScope
{
    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        return $query;
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
