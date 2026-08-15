<?php

namespace App\Support\Attributes;

use App\Support\DeletionPolicy;
use Attribute;

/**
 * Safe to delete — and `$reason` must say WHY, because "nobody classified this yet" and "we
 * decided this is fine" look identical from the outside, and only one of them is a money record
 * waiting to become deletable by accident.
 *
 * The reasons fall into three families, kept as prose rather than an enum because the useful part
 * is the specific sentence: **parent-managed** (deleting these IS the workflow), **configuration**
 * (setup data with no financial footprint), **operational** (records that describe work, not
 * money).
 *
 * @see DeletionPolicy
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class DeletionAllowed
{
    public function __construct(public string $reason) {}
}
