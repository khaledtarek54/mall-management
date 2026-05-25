<?php

namespace App\Filament\Admin\Resources\Concerns;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Companion trait for direct-FK resources (Unit, UtilityMeter,
 * CamExpensePool — models with an `asset_id` column). Filament's
 * built-in tenancy handles the normal case via
 * `$tenantOwnershipRelationshipName = 'asset'`. This trait only adds
 * the "All Properties" escape hatch: when the active tenant is the
 * synthetic ALL pseudo-asset, skip Filament's scoping so the user
 * sees a portfolio-wide list. Also bypasses SoftDeletingScope on
 * record route binding so trashed records resolve from their URL.
 */
trait BypassesScopingOnAll
{
    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        if ($tenant instanceof Asset && $tenant->isAllProperties()) {
            return $query;
        }

        return parent::scopeEloquentQueryToTenant($query, $tenant);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
