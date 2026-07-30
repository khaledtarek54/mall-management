<?php

namespace App\Support;

/**
 * Security defaults that must not live only in an env var.
 *
 * "The decision was deferred to an env var nobody set" is exactly how two-factor
 * enforcement stayed open on this project for months — so the production answer
 * ships in code, and the env var exists to OVERRIDE it rather than to supply it.
 */
class SecurityDefaults
{
    /**
     * Roles forced through TOTP setup in production.
     *
     * Everyone who can move money or change a tenancy. See config/security.php for
     * who is deliberately left out and why.
     */
    public const FORCE_2FA_ROLES = [
        'super_admin',
        'mall_admin',
        'manager',
        'accounting',
        'leasing',
        'operations',
        'coordinator',
        'hr',
        'marketing',
    ];
}
