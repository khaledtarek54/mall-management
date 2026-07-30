<?php

namespace App\Support;

/**
 * The recommended security posture, kept in code so it survives an empty .env.
 *
 * Two-factor enforcement is OPT-IN as of 2026-07-30 (operator's call — enrolment is a
 * rollout to schedule, not a surprise on deploy). This constant is therefore the list to
 * PASTE into SECURITY_FORCE_2FA_ROLES when the operator is ready, and the yardstick the
 * health check measures a production deploy against — not a value the config applies on
 * its own.
 *
 * It stays in code rather than only in .env.example because "the decision was deferred to
 * an env var nobody set" is exactly how enforcement stayed open here for months. Something
 * has to know what the right answer looks like in order to complain that it is missing.
 */
class SecurityDefaults
{
    /**
     * The roles that SHOULD hold a second factor: everyone who can move money or change
     * a tenancy. See config/security.php for who is deliberately left out and why.
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
