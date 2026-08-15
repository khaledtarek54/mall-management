<?php

namespace App\Support\Attributes;

use App\Support\PropertyIsolation;
use Attribute;

/**
 * The property record itself — it IS the Filament tenant, scoped by identity, so it belongs to
 * neither {@see PortfolioShared} nor {@see PropertyOwned}.
 *
 * A third classification rather than a special case of the other two, because "scoped by being the
 * thing" and "scoped by pointing at the thing" are different predicates, and collapsing them is how
 * a scoping bug gets written.
 *
 * @see PropertyIsolation
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class PropertyItself {}
