<?php

namespace App\Support\Attributes;

use App\Support\PropertyIsolation;
use Attribute;

/**
 * GLOBAL / shared: this model intentionally carries no per-property row scoping. It is an
 * operator-wide catalogue, configuration, or a person.
 *
 * Where a shared master is *used* per-property, the usage rows are {@see PropertyOwned} —
 * `Vendor` is shared, `VendorBill` and `VendorContract` are owned.
 *
 * @see PropertyIsolation
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class PortfolioShared {}
