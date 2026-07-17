<?php

namespace App\Support;

/**
 * Who may import (FR-USR-02).
 *
 * The FRD: *"The system shall restrict data import/upload functionality to **Admin users only**;
 * all other roles may export/download but not import."* — and its role table repeats it, giving the
 * Admin as "the only role that can import/upload data (e.g., CSV)".
 *
 * **Import is not a flavour of create.** Every ImportAction was gated on `canCreate()`, which made
 * every manager and the entire leasing team an importer. The FRD singles import out for a reason:
 * creating a tenant is one considered row, while one wrong CSV column rewrites hundreds at once and
 * the mistake is discovered later, in the billing.
 *
 * One place, so "who may import" cannot drift between the three import buttons — and so a fourth
 * one has an obvious thing to call.
 */
class Imports
{
    public const PERMISSION = 'imports.execute';

    public static function allowed(): bool
    {
        return auth()->user()?->can(self::PERMISSION) ?? false;
    }
}
