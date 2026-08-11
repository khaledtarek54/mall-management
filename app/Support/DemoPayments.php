<?php

namespace App\Support;

/**
 * Is the demo-payment shortcut available here?
 *
 * The shortcut writes a **real captured `Payment`** through the production capture path: AR moves,
 * the ledger posts `Dr Bank / Cr AR`, and the tenant is notified. That is the entire point in a
 * demo, and a catastrophe anywhere else — so the question "is this allowed in this environment?"
 * gets exactly one answer, in one place, and every dispatch point asks it.
 *
 * ## Why this class exists
 *
 * The gate used to be `! config('integrations.paymob.enabled')`, written out at three separate
 * dispatch points (the API controller and both portal actions). That predicate is **inverted with
 * respect to safety**: Paymob-off is the shipped default (`.env.example`), and
 * `PRODUCTION-RUNBOOK.md` prescribes flipping it off as the incident response — so the shortcut was
 * live exactly when the system was most exposed, including on production.
 *
 * Consequence, before this: an authenticated tenant could `POST /api/v1/me/invoices/{id}/pay-demo`
 * and mark their own invoice paid. `billing:reconcile` stayed **green** throughout, because every
 * internal relationship really was consistent — the money simply never existed. Chained with the
 * quick-lease wizard's default password, knowing a tenant's email address was enough.
 *
 * ## The rule
 *
 * Three conditions, all required:
 *
 *  1. **Never on production**, whatever the configuration says. This is the one that cannot be
 *     misconfigured, so it is checked first and independently of the flag.
 *  2. **An explicit opt-in flag**, defaulting off outside local/testing — so enabling it on a
 *     staging box carrying real-shaped data is a decision somebody made, not an accident.
 *  3. **Paymob still off.** The two payment paths must never both be live; a tenant offered both
 *     is a tenant who can choose the free one.
 *
 * `Health::checkDemoPayments()` **fails** a production environment where the flag is set, so the
 * dangerous intent cannot be silent even though condition 1 would refuse it. That is the same
 * treatment the 2FA opt-in gets, and for the same reason: this system has been bitten before by a
 * safety setting that was "configured" somewhere nobody looked.
 */
class DemoPayments
{
    /**
     * Does the environment forbid the shortcut outright, whatever the flag says?
     *
     * Public because `Health` needs the same answer, and asking it twice from two sources —
     * `app()->environment()` here, `config('app.env')` there — is how two guards come to disagree.
     */
    public static function forbiddenByEnvironment(): bool
    {
        return app()->environment('production');
    }

    public static function enabled(): bool
    {
        // 1. Production is refused outright — the flag is not consulted, so it cannot be wrong.
        if (self::forbiddenByEnvironment()) {
            return false;
        }

        // 3. Never alongside a live gateway.
        if (config('integrations.paymob.enabled')) {
            return false;
        }

        $flag = config('integrations.demo_payments.enabled');

        // 2. Unset means "follow the environment": on where demos and the test suite live, off
        // everywhere else. Staging counts as everywhere else — it carries real-shaped data, and a
        // fabricated payment there corrupts exactly the dry run the operator is trusting.
        return $flag === null
            ? app()->environment(['local', 'testing'])
            : (bool) $flag;
    }
}
