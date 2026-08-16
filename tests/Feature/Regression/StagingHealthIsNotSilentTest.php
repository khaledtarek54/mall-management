<?php

use App\Support\DemoPayments;
use App\Support\Deployment;
use App\Support\Health;

/**
 * Staging is not a laptop, and `atriom:health` must stop treating it like one.
 *
 * `Health` had **two** spellings of "is this a real box?", and both read as "is this production?":
 *
 *   - `! in_array(config('app.env'), ['local','testing'], true)` — 2FA, demo accounts, admin access,
 *     accounting readiness. Staging counted as production.
 *   - `app()->environment('production')` — demo payments, mobile reset URL, runtime drivers.
 *     Staging counted as a laptop.
 *
 * On the only two environments anybody had built they agree, so nothing ever surfaced. On a staging
 * box they are opposites: three checks reported `OK — not checked outside production` and said
 * nothing about a fabricated-payment endpoint being live, a password-reset link pointing at a route
 * this application does not have, and every `Cache::lock()` crossing the network. That is the box
 * PRODUCTION-RUNBOOK §12 tells the operator to rehearse the cut-over on twice and get the same
 * numbers both times.
 *
 * Every refusal below is paired with (a) a control that must PASS, so a check that simply went red
 * on everything could not satisfy it, and (b) a workstation case that must stay QUIET, so the fix
 * cannot be "fail everywhere" — which is how a health check stops being read.
 */
it('sorts each environment into exactly one tier, with the unanticipated one treated strictly', function () {
    inEnvironment('local');
    expect(Deployment::isWorkstation())->toBeTrue()
        ->and(Deployment::isDeployed())->toBeFalse()
        ->and(Deployment::isPreProduction())->toBeFalse();

    inEnvironment('production');
    expect(Deployment::isProduction())->toBeTrue()
        ->and(Deployment::isDeployed())->toBeTrue()
        ->and(Deployment::isPreProduction())->toBeFalse();

    inEnvironment('staging');
    expect(Deployment::isPreProduction())->toBeTrue()
        ->and(Deployment::isDeployed())->toBeTrue()
        ->and(Deployment::isWorkstation())->toBeFalse();

    // An environment nobody anticipated must inherit the STRICTER treatment, never the laxer one —
    // otherwise the next box someone names `uat` re-opens all three holes at once.
    inEnvironment('uat');
    expect(Deployment::isDeployed())->toBeTrue()
        ->and(Deployment::isPreProduction())->toBeTrue();
});

it('reports a mobile reset link that goes nowhere on staging, not only on production', function () {
    inEnvironment('staging');
    config()->set('app.url', 'https://staging.atriom.example');
    config()->set('app.mobile_reset_url', '');

    $check = Health::run()['checks']['mobile_reset_url'];

    // Staging is where the mobile app is pointed before it ships, so this is the box where a 404ing
    // reset link is still cheap to find.
    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('APP_MOBILE_RESET_URL');

    // Control: configured, and the same check on the same box passes.
    config()->set('app.mobile_reset_url', 'atriom://reset-password');
    expect(Health::run()['checks']['mobile_reset_url']['ok'])->toBeTrue();

    // And it must still stay quiet on a laptop, where the 404 is obvious and harmless.
    inEnvironment('local');
    config()->set('app.mobile_reset_url', '');
    expect(Health::run()['checks']['mobile_reset_url']['ok'])->toBeTrue();
});

it('reports database-backed cache, session and queue on staging, not only on production', function () {
    inEnvironment('staging');
    config()->set('cache.default', 'database');
    config()->set('session.driver', 'database');
    config()->set('queue.default', 'database');

    $check = Health::run()['checks']['runtime_drivers'];

    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('cache')
        ->and($check['detail'])->toContain('session')
        ->and($check['detail'])->toContain('queue')
        // The message must name the box it is talking about, not say "production" on staging.
        ->and($check['detail'])->toContain('staging');

    // Control: INFRASTRUCTURE.md §5 gives staging its own Redis keyspace; once it is on it, quiet.
    config()->set('cache.default', 'redis');
    config()->set('session.driver', 'redis');
    config()->set('queue.default', 'redis');
    expect(Health::run()['checks']['runtime_drivers']['ok'])->toBeTrue();

    // Local and CI run on `database` deliberately and must not turn red.
    inEnvironment('local');
    config()->set('cache.default', 'database');
    config()->set('session.driver', 'database');
    config()->set('queue.default', 'database');
    expect(Health::run()['checks']['runtime_drivers']['ok'])->toBeTrue();
});

it('raises the alarm when the demo-payment shortcut is live on staging', function () {
    inEnvironment('staging');
    config()->set('integrations.paymob.enabled', false);
    config()->set('integrations.demo_payments.enabled', true);

    // The GUARD is unchanged and still permits the explicit opt-in — that decision is deliberate
    // and pinned by DemoPaymentEnvironmentGuardTest. What changed is that it is no longer silent.
    expect(DemoPayments::enabled())->toBeTrue('the opt-in itself must stay legal');

    $check = Health::run()['checks']['demo_payments'];

    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('staging')
        // On production the flag is only an INTENT — forbiddenByEnvironment() overrides it. Here
        // nothing overrides it, so the detail has to say what is actually true of this box.
        ->and($check['detail'])->toContain('Dr Bank / Cr AR');

    // Control: unset the flag and the same box reports clean.
    config()->set('integrations.demo_payments.enabled', null);
    expect(DemoPayments::enabled())->toBeFalse()
        ->and(Health::run()['checks']['demo_payments']['ok'])->toBeTrue();
});

it('leaves the production and workstation verdicts on demo payments exactly as they were', function () {
    // The restructured check must not have moved either end while fixing the middle.
    inEnvironment('production');
    config()->set('integrations.paymob.enabled', false);
    config()->set('integrations.demo_payments.enabled', true);

    $production = Health::run()['checks']['demo_payments'];
    expect($production['ok'])->toBeFalse()
        ->and($production['detail'])->toContain('DEMO_PAYMENTS_ENABLED');

    config()->set('integrations.demo_payments.enabled', null);
    expect(Health::run()['checks']['demo_payments']['ok'])->toBeTrue();

    // A laptop with the shortcut live is the expected state, not a finding.
    inEnvironment('testing');
    config()->set('integrations.demo_payments.enabled', true);
    expect(Health::run()['checks']['demo_payments']['ok'])->toBeTrue();
});

it('still treats staging as production for the checks that already did', function () {
    // The unification moved these onto one reading of the environment; it must not have relaxed
    // them. Each was already strict on staging and has to stay strict.
    inEnvironment('staging');
    config()->set('security.force_2fa_roles', []);

    expect(Health::run()['checks']['two_factor']['ok'])->toBeFalse()
        ->and(Health::run()['checks']['two_factor']['detail'])->toContain('staging');
});
