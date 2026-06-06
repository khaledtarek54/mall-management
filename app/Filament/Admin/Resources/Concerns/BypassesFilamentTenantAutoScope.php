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
    /**
     * Opt out of Filament's automatic tenancy entirely. These resources scope
     * reads themselves in `getEloquentQuery()`, and their models carry no
     * `asset` ownership relationship.
     *
     * Returning false stops Panel::boot() from BOTH adding Filament's own
     * tenant global scope AND registering the model `creating`/`created` hooks
     * (observeTenancyModelCreation). Those hooks resolve the panel's default
     * ownership relationship — `asset` — and threw a LogicException on every
     * create while a property tenant was active (tenant / invoice / credit
     * note creation all 500'd). Overriding `scopeEloquentQueryToTenant` alone
     * was not enough: it only neutralises the read scope, not the create hook.
     */
    public static function isScopedToTenant(): bool
    {
        return false;
    }

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
