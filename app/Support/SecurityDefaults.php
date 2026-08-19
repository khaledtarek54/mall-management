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

    /**
     * The environments that are a workstation rather than a deployment.
     *
     * `App\Support\Deployment` answers the same question for the RUNNING application, and is
     * the right thing to use everywhere except here: config files are evaluated before the
     * container exists, so they cannot ask it. Kept as one constant so the two readings of
     * "is this a real box?" cannot drift into disagreeing.
     */
    public const WORKSTATION_ENVIRONMENTS = ['local', 'testing'];

    /**
     * Should session payloads be encrypted at rest, absent an explicit SESSION_ENCRYPT?
     *
     * Sessions are stored in the `sessions` table, so an unencrypted payload is readable by
     * anything that can read the database — a restored backup, a support query, a compromised
     * read replica. On by default for a deployment, off on a workstation.
     *
     * This lives in code rather than inline in `config/session.php` so it can be tested
     * directly. A default that can only be observed by re-evaluating a config file under a
     * mutated environment is a default nothing asserts, and an unasserted security default is
     * how `.env.example` came to pin `SESSION_ENCRYPT=false` and quietly defeat it.
     */
    public static function encryptSessionsByDefault(?string $environment = null): bool
    {
        $environment ??= (string) env('APP_ENV', 'production');

        return ! in_array($environment, self::WORKSTATION_ENVIRONMENTS, true);
    }
}
