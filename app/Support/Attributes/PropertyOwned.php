<?php

namespace App\Support\Attributes;

use App\Support\PropertyIsolation;
use Attribute;

/**
 * Every row of this model is isolated to exactly one property.
 *
 * `$via` is the route to the `asset_id`:
 *
 *  - **null** — the model carries a direct `asset_id` column.
 *  - **a relation chain** (`'unit'`, `'lease.unit'`, `'invoices.lease.unit'`) — the property is
 *    reached through a relationship, and reads are scoped with `whereHas` over that chain.
 *
 * Declaring the chain here rather than in a resource is what lets ONE `getEloquentQuery()` scope
 * every property-owned resource, direct and indirect alike.
 *
 * @see PropertyIsolation
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class PropertyOwned
{
    public function __construct(public ?string $via = null) {}
}
