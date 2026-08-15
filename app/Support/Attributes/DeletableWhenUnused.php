<?php

namespace App\Support\Attributes;

use App\Support\DeletionPolicy;
use Attribute;

/**
 * Master data with history: deletable only while nothing references it, otherwise deactivate.
 *
 * `$blockedBy` names the relations that constitute history. They are verified to EXIST by
 * `DeletionPolicyConformanceTest` — a mistyped relation blocks nothing and looks identical to a
 * working guard, which is the silent failure this whole register exists to prevent.
 *
 * Under-populating it is the other trap, and the more expensive one: omitting a relation that
 * cascades let a force-delete destroy a NEVER-deletable money record through its parent.
 *
 * @see DeletionPolicy
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class DeletableWhenUnused
{
    /** @param  array<int, string>  $blockedBy  relation names on this model */
    public function __construct(
        public array $blockedBy,
        public string $instead,
    ) {}
}
