<?php

namespace App\Filament\Admin\Resources\Concerns;

use App\Support\Attributes\PropertyOwned;
use App\Support\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use ReflectionClass;
use RuntimeException;

/**
 * **The one place a property-owned resource is scoped to the selected property.**
 *
 * Every rule this applies is read from the model's own `#[PropertyOwned]` attribute, so a resource
 * says only *that* it is scoped, never *how* — the how belongs with the data.
 *
 * ## Why this exists
 *
 * Measured across `app/Filament/Admin/Resources` before this trait: **16 distinct
 * `getEloquentQuery()` bodies** doing the same job. Eighteen were byte-identical after whitespace
 * normalisation, five differed only by an `orWhereNull`, and the rest were one-offs. Sixteen chances
 * to get a scoping rule subtly wrong, in the one invariant whose failure mode is silent — a
 * misscoped list does not throw, it shows one property's records on another property's screen.
 *
 * ## The three cases, all driven by the attribute
 *
 *  - `#[PropertyOwned]` — direct `asset_id` column. Strict: `where('asset_id', X)`.
 *  - `#[PropertyOwned(via: 'lease.unit')]` — reached through a relation. `whereHas` the chain.
 *  - `#[PropertyOwned(portfolioRowsWhenNull: true)]` — nullable column where a null row is
 *    portfolio-level overhead every property must still see, so `X OR NULL`.
 *
 * ## The fallback is the load-bearing part
 *
 * With no property selected, a RESTRICTED user is still pinned to their assigned set —
 * `visibleAssetIds()` returns null only for super_admin / unconstrained. **Never fall through to an
 * unscoped query**: that is the exact bug that leaked every property's records to restricted roles
 * in All-Properties mode.
 *
 * ## Adding to the query
 *
 * A resource that also needs an eager-load or aggregate defines its own `getEloquentQuery()` — a
 * method on the class wins over a trait's — and reuses the scoping rather than re-implementing it:
 *
 *     public static function getEloquentQuery(): Builder
 *     {
 *         return static::scopeToProperty(parent::getEloquentQuery())->withCount(['items']);
 *     }
 */
trait ScopesToProperty
{
    use BypassesFilamentTenantAutoScope;

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToProperty(parent::getEloquentQuery());
    }

    /**
     * Narrow a query to the property in scope, per the model's own declaration.
     *
     * Public and static so the bespoke resources can compose with it instead of copying it back.
     */
    public static function scopeToProperty(Builder $query): Builder
    {
        $declared = static::propertyOwnership();
        $assetId = TenantScope::currentAssetId();
        $visible = $assetId === null ? TenantScope::visibleAssetIds() : null;

        // Portfolio-wide AND unconstrained — the only case that legitimately sees everything.
        if ($assetId === null && $visible === null) {
            return $query;
        }

        $column = 'asset_id';
        $nullable = $declared->portfolioRowsWhenNull;

        $constrain = function (Builder $q) use ($assetId, $visible, $column, $nullable): void {
            $q->where(function (Builder $inner) use ($assetId, $visible, $column, $nullable): void {
                $assetId !== null
                    ? $inner->where($column, $assetId)
                    : $inner->whereIn($column, $visible);

                if ($nullable) {
                    // Portfolio-level overhead: owned by no single mall, visible from all of them.
                    $inner->orWhereNull($column);
                }
            });
        };

        return $declared->via === null
            ? tap($query, $constrain)
            : $query->whereHas($declared->via, $constrain);
    }

    /**
     * This resource's model declaration.
     *
     * Throws rather than defaulting: a resource that uses this trait has stated it is
     * property-owned, so a model with no `#[PropertyOwned]` is a contradiction. Silently returning
     * an unscoped query here would be the leak this trait exists to prevent, and it would look
     * exactly like a working screen.
     */
    protected static function propertyOwnership(): PropertyOwned
    {
        $model = static::$model ?? null;

        $found = $model === null ? [] : (new ReflectionClass($model))->getAttributes(PropertyOwned::class);

        if ($found === []) {
            throw new RuntimeException(sprintf(
                '%s uses ScopesToProperty but %s carries no #[PropertyOwned] attribute.',
                static::class,
                $model ?? 'its model',
            ));
        }

        return $found[0]->newInstance();
    }
}
