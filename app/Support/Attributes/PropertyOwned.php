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
 * **`$portfolioRowsWhenNull` is a third case, and it is a real behavioural difference rather than
 * drift.** Five money models (`Expense`, `VendorBill`, `JournalEntry`, `Payroll`,
 * `DepositTransaction`) have a NULLABLE `asset_id` where a null row is portfolio-level overhead
 * that every property must still see — an operator-wide insurance bill is not hidden because you
 * picked a mall. Their queries therefore read `where(asset_id = X OR asset_id IS NULL)`.
 *
 * Scoping one of those strictly would HIDE those rows from every screen, and scoping a strict model
 * loosely would SHOW it rows belonging to nobody. Neither fails loudly, which is why the flag is
 * declared on the model rather than left to whoever writes the next resource.
 *
 * @see PropertyIsolation
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class PropertyOwned
{
    /**
     * @param  string|null  $via  relation chain to a model with `asset_id`; null = direct column
     * @param  bool  $portfolioRowsWhenNull  the column is NULLABLE and a null means "portfolio-level,
     *                                       visible from every property" rather than "unclassified"
     */
    public function __construct(
        public ?string $via = null,
        public bool $portfolioRowsWhenNull = false,
    ) {}
}
