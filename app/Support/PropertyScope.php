<?php

namespace App\Support;

use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Builder;
use ReflectionClass;
use RuntimeException;

/**
 * **NARROWING A QUERY TO THE PROPERTY IN SCOPE — the one implementation of the rule.**
 *
 * Every rule here is read from the model's own `#[PropertyOwned]` attribute, so a caller says only
 * *that* it is scoped, never *how* — the how belongs with the data. See
 * [docs/PROPERTY-ISOLATION.md](../../docs/PROPERTY-ISOLATION.md).
 *
 * ## Why it moved out of `ScopesToProperty`
 *
 * That trait was written for RESOURCES, and its docblock records what it replaced: sixteen
 * `getEloquentQuery()` bodies doing the same job, in the one invariant whose failure mode is silent
 * — a misscoped list does not throw, it shows one property's records on another property's screen.
 *
 * **The same drift then happened again one layer down, where no trait was watching.** A RELATION
 * MANAGER under a portfolio-shared owner lists rows that span malls — a tenant trades in several —
 * and the nine such tables had scoped themselves FIVE different ways:
 * `whereHas('unit', …whereIn)`, a direct `whereIn('asset_id', …)`, an `->inProperties()` scope, a
 * borrowed `StockMovementResource::scopeToProperty()`, and **twice, nothing at all**. This class is
 * that rule extracted on its second real call site, so the tenth table cannot invent a sixth
 * spelling.
 *
 * ## The fallback is the load-bearing part
 *
 * With no property selected, a RESTRICTED user is still pinned to their assigned set —
 * `visibleAssetIds()` returns null only for super_admin / unconstrained. **Never fall through to an
 * unscoped query**: that is the exact bug that leaked every property's records to restricted roles
 * in All-Properties mode.
 */
final class PropertyScope
{
    /**
     * Narrow `$query` to the property in scope, per `$model`'s own declaration.
     *
     * `$context` names the caller in the refusal, because an unclassified model is a contradiction
     * worth reporting against the screen that asked rather than against the register.
     *
     * @param  class-string  $model
     */
    public static function apply(Builder $query, string $model, string $context): Builder
    {
        $declared = self::declarationFor($model, $context);
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
     * A model's property declaration.
     *
     * Throws rather than defaulting: a caller reaching this has stated it is scoping to the
     * property, so a model with no `#[PropertyOwned]` is a contradiction. Silently returning an
     * unscoped query here would be the leak this class exists to prevent, and it would look exactly
     * like a working screen.
     *
     * @param  class-string  $model
     */
    public static function declarationFor(string $model, string $context): PropertyOwned
    {
        $found = $model === '' ? [] : (new ReflectionClass($model))->getAttributes(PropertyOwned::class);

        if ($found === []) {
            throw new RuntimeException(sprintf(
                '%s scopes to the property but %s carries no #[PropertyOwned] attribute.',
                $context,
                $model === '' ? 'its model' : $model,
            ));
        }

        return $found[0]->newInstance();
    }
}
