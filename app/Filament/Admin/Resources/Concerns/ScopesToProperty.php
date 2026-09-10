<?php

namespace App\Filament\Admin\Resources\Concerns;

use App\Support\Attributes\PropertyOwned;
use App\Support\PropertyScope;
use Illuminate\Database\Eloquent\Builder;

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
        // The rule itself lives in `App\Support\PropertyScope` — extracted when the RELATION
        // MANAGERS turned out to need the same answer and had grown five spellings of it between
        // them. This trait keeps the resource-facing name and the refusal wording its callers read.
        return PropertyScope::apply($query, static::getModel(), static::class);
    }

    /**
     * This resource's model declaration.
     *
     * Kept as the resource-facing name for `PropertyScope::declarationFor()` — a bespoke resource
     * composing its own query can ask what its model declared without reaching past this trait. It
     * no longer sits on the path `scopeToProperty()` takes, so overriding it would NOT change the
     * scope; that is stated here because the previous comment described a `throw below` that has
     * moved, and a docblock describing a control flow that no longer exists is how the next reader
     * is misled.
     */
    protected static function propertyOwnership(): PropertyOwned
    {
        return PropertyScope::declarationFor(static::getModel(), static::class);
    }
}
