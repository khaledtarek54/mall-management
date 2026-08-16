<?php

namespace App\Support;

/**
 * What KIND of box is this — a workstation, a deployed rehearsal, or the real one?
 *
 * ## Why this exists
 *
 * Every safety check in `Health` has to answer "does this state matter here?", and until this class
 * there were **two different answers in the same file**:
 *
 *   - `! in_array(config('app.env'), ['local','testing'], true)` — used by the 2FA, demo-account
 *     and admin-access checks. Staging counts as production.
 *   - `app()->environment('production')` — used by the demo-payment, mobile-reset and
 *     runtime-driver checks. Staging counts as a laptop.
 *
 * Both spellings read as "is this production?", and on the only two environments anybody had built
 * they agree. **On a staging box they are opposites**, so three checks went silent on exactly the
 * box the operator spins up to rehearse the cut-over — the mobile password-reset link pointing at a
 * route that does not exist, cache/session/queue locks crossing the network, and a demo-payment
 * shortcut that fabricates `Dr Bank / Cr AR`. None of them said a word, and the health table
 * reported `OK — not checked outside production` for all three.
 *
 * ## The three tiers
 *
 * - **Workstation** (`local`, `testing`) — a developer's laptop or the test runner. Demo data,
 *   database-backed drivers and the demo-payment shortcut are all *correct* here, so a check that
 *   fires is a check that gets ignored.
 * - **Pre-production** (anything else that is not `production` — `staging`, `uat`, a pilot box) —
 *   deployed, reachable, and carrying real-shaped data. Everything that would be wrong on
 *   production is wrong here too; what differs is only that losing this box costs nothing.
 * - **Production** — real money, real tax documents.
 *
 * ## Which source of truth, and why it matters
 *
 * `app()->environment()`, never `config('app.env')`. The two **provably diverge**: Laravel resolves
 * `environment()` from the container's `env` binding, which `LoadConfiguration` stamps once at
 * boot — so `config(['app.env' => 'production'])` leaves `app()->environment()` reading `local`.
 * On a real box both come from the same `.env` line and agree; in a *test* they do not, which is
 * how a guard and the test pinning it can read two different environments and still look green.
 *
 * `DemoPayments` already reads `app()->environment()`, and `Health::checkDemoPayments()`'s own
 * comment gives the rule: a guard that reads the environment differently from the thing it guards
 * is not a guard. This class is that one reading.
 */
final class Deployment
{
    /**
     * A developer's laptop or the test runner.
     *
     * The one tier where demo data, `database` drivers and fabricated payments are the expected
     * state rather than a finding.
     */
    public static function isWorkstation(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    /** The box carrying real money and filing real tax documents. */
    public static function isProduction(): bool
    {
        return app()->environment('production');
    }

    /**
     * Deployed, but not production — staging, UAT, a pilot.
     *
     * Deliberately defined as "neither of the other two" rather than as a list of names: an
     * environment nobody anticipated should inherit the STRICTER treatment, not the laxer one.
     */
    public static function isPreProduction(): bool
    {
        return ! self::isWorkstation() && ! self::isProduction();
    }

    /**
     * Anything that is not somebody's laptop — staging *and* production.
     *
     * This is the predicate almost every safety check wants. A check that is only about the live
     * box (nothing here is, today) should ask `isProduction()` explicitly and say why.
     */
    public static function isDeployed(): bool
    {
        return ! self::isWorkstation();
    }

    /** The environment's own name, for messages that should not guess at it. */
    public static function name(): string
    {
        return (string) app()->environment();
    }
}
