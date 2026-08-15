<?php

namespace App\Support\Attributes;

use App\Support\DeletionPolicy;
use Attribute;

/**
 * This model is a money or audit record: **never deletable, not even by super_admin.**
 *
 * Declared on the model rather than in a central array so that adding one costs one file instead
 * of nine. {@see DeletionPolicy} derives the register from these declarations, and
 * `DeletionPolicyConformanceTest` still discovers models by globbing app/Models — so a model that
 * declares nothing is still an unclassified model that fails the build, exactly as before.
 *
 * `$correction` is the workflow the operator uses instead — cancel, void, credit note, reverse.
 * "You cannot delete this" without "do X instead" only moves their problem.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class NeverDeletable
{
    public function __construct(public string $correction) {}
}
