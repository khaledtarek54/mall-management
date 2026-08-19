<?php

use App\Support\Health;
use App\Support\SecurityDefaults;

/**
 * Who may call this application from a browser, and whether session payloads are readable
 * in the database.
 *
 * `config/cors.php` did not exist until 2026-08-19. That was not a neutral absence:
 * `HandleCors` is in Laravel's global middleware stack whatever the config says, so with
 * no file to read it applied the framework's own fallback — `allowed_origins: ['*']` over
 * `api/*` and `sanctum/csrf-cookie`. Verified before the fix by dumping the resolved
 * config, not inferred from the missing file.
 *
 * The honest scope, because a fix oversold is a fix nobody trusts: `/api/v1` is Bearer-token
 * authenticated and `supports_credentials` is false, so the wildcard never handed a third
 * party a tenant's data — a cross-origin call without a token gets a 401. What it did expose
 * was the unauthenticated shopper feed (`/api/v1/public/*`, module 36) to any origin, and,
 * more importantly, it meant the policy was an accident rather than a decision.
 *
 * These tests exist because the config alone cannot hold the line. A file can be deleted; an
 * allow-list can be pasted wide during a debugging session and never narrowed. So the health
 * check refuses a DEPLOYED box with a wildcard, and the tests below prove the refusal by
 * putting the bad state in and watching it go red — a check that only ever sees good input
 * has never demonstrated it can fail.
 *
 * @see config/cors.php
 */
it('refuses a deployed environment that allows any browser origin', function () {
    inEnvironment('production');
    config()->set('cors.allowed_origins', ['*']);
    config()->set('session.encrypt', true);

    $check = Health::run()['checks']['browser_origin_policy'];

    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('ANY origin');
});

it('refuses a deployed environment storing session payloads unencrypted', function () {
    inEnvironment('production');
    config()->set('cors.allowed_origins', ['https://atriom.example']);
    config()->set('session.encrypt', false);

    $check = Health::run()['checks']['browser_origin_policy'];

    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('NOT encrypted');
});

/**
 * The pair that actually leaks a session, called out separately because either half alone
 * is a defensible posture and only the combination is a hole: a wildcard origin plus
 * credentials lets a hostile page call the API as the signed-in user.
 */
it('names the wildcard-plus-credentials combination specifically', function () {
    inEnvironment('production');
    config()->set('cors.allowed_origins', ['*']);
    config()->set('cors.supports_credentials', true);
    config()->set('session.encrypt', true);

    $check = Health::run()['checks']['browser_origin_policy'];

    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('as the signed-in user');
});

/**
 * An empty allow-list is not "secure by default", it is a broken deploy — APP_URL unset
 * means no browser origin can reach /api/* at all. Reported as its own fault so nobody
 * debugs it as a routing problem.
 */
it('refuses a deployed environment whose allow-list is empty', function () {
    inEnvironment('production');
    config()->set('cors.allowed_origins', []);
    config()->set('cors.allowed_origins_patterns', []);
    config()->set('session.encrypt', true);

    $check = Health::run()['checks']['browser_origin_policy'];

    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('EMPTY');
});

/**
 * The control. Every refusal above would also pass if the check simply always failed, so a
 * correctly-configured deployment must come back green — otherwise these tests prove
 * nothing about the predicate, only that it returns false.
 */
it('passes on a correctly configured deployment', function () {
    inEnvironment('production');
    config()->set('cors.allowed_origins', ['https://atriom.example']);
    config()->set('cors.supports_credentials', false);
    config()->set('session.encrypt', true);

    $check = Health::run()['checks']['browser_origin_policy'];

    expect($check['ok'])->toBeTrue();
});

/**
 * A workstation is not a deployment. Developers run wide-open CORS and unencrypted sessions
 * on purpose, and a health check that scolds them there is a health check they learn to
 * ignore — which is how the 2FA enforcement gap survived for months.
 */
it('does not scold a workstation', function () {
    inEnvironment('local');
    config()->set('cors.allowed_origins', ['*']);
    config()->set('session.encrypt', false);

    $check = Health::run()['checks']['browser_origin_policy'];

    expect($check['ok'])->toBeTrue()
        ->and($check['detail'])->toContain('local/testing');
});

/**
 * The shipped defaults, asserted directly. The tests above all set config by hand, so they
 * would pass even if `config/cors.php` were deleted again — this is the one that would not.
 */
it('ships a config file that does not allow every origin', function () {
    expect(file_exists(config_path('cors.php')))->toBeTrue(
        'config/cors.php must exist — without it HandleCors falls back to allowed_origins: [*]'
    );

    $shipped = require config_path('cors.php');

    expect($shipped['allowed_origins'])->not->toContain('*')
        ->and($shipped['supports_credentials'])->toBeFalse()
        ->and($shipped['paths'])->toContain('api/*');
});

/**
 * Session encryption defaults ON for a deployment and OFF on a workstation — the same shape
 * as `security.force_https`, and for the same reason: a posture that depends on someone
 * remembering an env var is a posture you do not have.
 *
 * Asserted against `SecurityDefaults` rather than by re-evaluating `config/session.php` under
 * a mutated environment. That was tried first and it does not work in-process: an explicit
 * `SESSION_ENCRYPT` in the environment wins over the default, so the test observed the env
 * file rather than the decision. Which is the failure mode itself — `.env.example` pinned
 * `SESSION_ENCRYPT=false`, a deploy copies that file, and the safe default could never apply.
 * The example now ships the line commented out.
 */
it('defaults session encryption on for a deployment and off locally', function () {
    expect(SecurityDefaults::encryptSessionsByDefault('production'))->toBeTrue()
        ->and(SecurityDefaults::encryptSessionsByDefault('staging'))->toBeTrue()
        ->and(SecurityDefaults::encryptSessionsByDefault('local'))->toBeFalse()
        ->and(SecurityDefaults::encryptSessionsByDefault('testing'))->toBeFalse();
});

/**
 * And the config actually reads that decision — otherwise the function above could be
 * correct while `config/session.php` hard-codes false beside it.
 */
it('wires the session config to that decision', function () {
    $source = (string) file_get_contents(config_path('session.php'));

    expect($source)->toContain('SecurityDefaults::encryptSessionsByDefault()')
        ->and($source)->toContain("env('SESSION_ENCRYPT'");
});

/**
 * `.env.example` must not pin the insecure value. A deploy copies that file, so a line there
 * overrides the config default entirely — this is the exact shape that defeated the safe log
 * channel when the example pinned `LOG_STACK=single`.
 */
it('does not ship an env example that pins session encryption off', function () {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)->not->toMatch('/^SESSION_ENCRYPT=false/m');
});
