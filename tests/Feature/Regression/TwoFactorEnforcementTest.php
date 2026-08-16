<?php

/**
 * Two-factor enforcement was configured, documented, and enforced on nobody.
 *
 * The panel wired it up like this:
 *
 *     ->forceTwoFactorSetup(fn (): bool => auth()->user()?->hasAnyRole(...) === true)
 *
 * The plugin calls `evaluate($condition)` the moment the panel is REGISTERED —
 * during boot, before any request is authenticated. `auth()->user()` is null
 * there, so the closure returned false, the plugin stored a plain `false`, and
 * the panel's `array_filter` dropped the enforcement middleware entirely.
 *
 * The result: **no role had 2FA enforced, super_admin included**, while
 * `config/security.php`, `SECURITY_FORCE_2FA_ROLES` and the roadmap all described
 * a working mechanism. Setting the env var — the "fix" the roadmap proposed —
 * would have changed nothing at all, and left everyone believing the panel was
 * protected. That is worse than a control known to be absent.
 *
 * It is the same trap this codebase already documented on `->colors()`: a Filament
 * panel-builder argument is evaluated once, at boot. A predicate about the CURRENT
 * USER cannot live there. The role decision now lives in a middleware, which runs
 * per request.
 *
 * These tests are behavioural on purpose — they drive real HTTP requests through
 * the panel. Asserting the config array would have passed the whole time the
 * mechanism was dead.
 */

use App\Http\Middleware\ForceTwoFactorForRoles;
use App\Support\Health;
use App\Support\SecurityDefaults;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'TFA']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** Visit the panel as a user holding $role, with the given roles forced. */
function tfaVisit(string $role, array $forced): TestResponse
{
    config()->set('security.force_2fa_roles', $forced);

    $user = makeUser($role, [test()->asset->id]);
    expect($user->two_factor_secret)->toBeNull('Fixture already has 2FA — the test would prove nothing.');

    return test()->actingAs($user)->get('/admin/'.test()->asset->code);
}

it('actually redirects a forced role to TOTP setup', function () {
    // The regression. This returned 200 for the entire life of the feature.
    $response = tfaVisit('super_admin', ['super_admin']);

    $response->assertRedirect();

    expect($response->headers->get('Location'))->toContain('two-factor');
});

it('forces an ACCOUNTING user — the role that records payments', function () {
    // The roadmap's actual concern: roles that move money were never covered.
    $response = tfaVisit('accounting', ['super_admin', 'accounting']);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('two-factor');
});

it('lets a role OUTSIDE the list straight through', function () {
    // A guard that stops everyone is not a guard, it is an outage.
    tfaVisit('viewer', ['super_admin'])->assertSuccessful();
});

it('forces nobody when the list is empty', function () {
    // The local/testing default. The demo logins and the Playwright suite depend
    // on this: enforcement that fires locally would redirect every E2E spec to a
    // setup page.
    tfaVisit('super_admin', [])->assertSuccessful();
});

/* ---- the trap that caused it -------------------------------------------- */

it('registers the enforcement middleware unconditionally', function () {
    // The heart of it. The middleware must be attached to the panel ALWAYS, with
    // the role decision made later, per request. If someone re-introduces a
    // closure on the panel builder, the plugin evaluates it at boot against a null
    // user and quietly drops this middleware again.
    $middleware = Filament::getPanel('admin')->getAuthMiddleware();

    expect(in_array(ForceTwoFactorForRoles::class, $middleware, true))->toBeTrue(
        'The 2FA enforcement middleware is not on the panel — enforcement is off for everyone, silently.'
    );
});

it('decides by role at request time, not at boot', function () {
    // Directly pins the thing the panel builder could not do: the same middleware
    // instance answers differently for two users.
    config()->set('security.force_2fa_roles', ['accounting']);

    $middleware = app(ForceTwoFactorForRoles::class);

    expect($middleware->requiresTwoFactor(makeUser('accounting', [$this->asset->id])))->toBeTrue()
        ->and($middleware->requiresTwoFactor(makeUser('viewer', [$this->asset->id])))->toBeFalse()
        ->and($middleware->requiresTwoFactor(null))->toBeFalse();
});

/* ---- the production default --------------------------------------------- */

it('covers every money-touching role in the recommended list', function () {
    // Enforcement is OPT-IN (operator's call 2026-07-30) — this constant is what you paste into
    // SECURITY_FORCE_2FA_ROLES, and what the health check measures a production deploy against.
    // If a role is added that handles money, it belongs here.
    $recommended = SecurityDefaults::FORCE_2FA_ROLES;

    foreach (['super_admin', 'mall_admin', 'manager', 'accounting', 'leasing', 'operations'] as $role) {
        expect(in_array($role, $recommended, true))->toBeTrue(
            "{$role} can move money or change tenancies but is missing from the recommended 2FA list."
        );
    }
});

it('forces nobody until the operator opts in', function () {
    // The deliberate default. Enrolment is a rollout to schedule with staff, so it must not
    // start happening because of a deploy — including in production, unlike force_https.
    expect(config('security.force_2fa_roles'))->toBe([]);
});

it('does not quietly ship a production deploy with no second factor', function () {
    // The counterweight to opting out. "Deferred to an env var nobody set" is how enforcement
    // sat broken here for months; off is allowed, but never silent.
    $check = new ReflectionMethod(Health::class, 'checkTwoFactor');
    $check->setAccessible(true);

    inEnvironment('production');

    config()->set('security.force_2fa_roles', []);
    $unset = $check->invoke(null);

    config()->set('security.force_2fa_roles', SecurityDefaults::FORCE_2FA_ROLES);
    $enforced = $check->invoke(null);

    inEnvironment('testing');
    config()->set('security.force_2fa_roles', []);
    $local = $check->invoke(null);

    expect($unset['ok'])->toBeFalse('a production deploy with no 2FA must fail the health check')
        ->and($unset['detail'])->toContain('SECURITY_FORCE_2FA_ROLES')
        ->and($enforced['ok'])->toBeTrue()
        ->and($local['ok'])->toBeTrue('local/testing must stay unforced without failing health');
});
