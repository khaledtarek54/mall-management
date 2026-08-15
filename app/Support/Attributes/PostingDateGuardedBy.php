<?php

namespace App\Support\Attributes;

use App\Support\PostingDateGuards;
use Attribute;

/**
 * This GL source's posting date is operator-typed, and `$guard` is the class that refuses a date
 * whose accounting period is CLOSED.
 *
 * Guard in the SERVICE where one exists — it can refuse before the first side effect. Point at the
 * model itself only where there is no create/update service and the Filament resource writes the
 * model directly, because then the model's save is the one choke point every path shares. Guarding
 * a single admin page is what let a captured payment's date be moved into a closed month from the
 * edit form, the portal, the mobile API and the console.
 *
 * A MISSING period is allowed; only a CLOSED one is refused.
 *
 * @see PostingDateGuards
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class PostingDateGuardedBy
{
    /** @param  class-string  $guard */
    public function __construct(public string $guard) {}
}
