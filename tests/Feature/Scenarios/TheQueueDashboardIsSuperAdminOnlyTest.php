<?php

use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Tests\Support\RoleMatrix;

/**
 * `/horizon` is a screen, and it is the one screen no other gate in this project
 * can see.
 *
 * `EveryRoleMeetsEveryScreenTest` discovers screens from the Filament panel and
 * `RoleScreenMatrixShard{1..5}Test` drives them over the real route; both are
 * structurally blind to a package that registers its own routes. The rule those
 * gates exist to enforce — "a screen no role is refused is a screen with no
 * lock" — still applies, so it is asserted here by hand.
 *
 * It is not a read-only screen either: the dashboard lists pending and failed
 * job PAYLOADS (a serialised job names tenants, invoice ids and amounts) and
 * carries verbs — retry a failed job, pause the queue. Retrying a posting job is
 * not a read.
 *
 * Each refusal is paired with the control that must succeed, because a gate that
 * refused everybody would satisfy the refusals alone and read as a pass.
 */
// The REAL catalogue, not `seedRoles()`. That helper creates six roles; the seeder creates
// fourteen, and a sweep run against the six would silently skip the eight narrow roles — which are
// exactly the ones a lock is most likely to be wrong about.
beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

it('refuses a visitor who is not signed in', function () {
    $this->get('/horizon')->assertForbidden();
});

it('refuses every role in the panel except super_admin', function () {
    // SWEPT, not sampled. `EveryRoleMeetsEveryScreenTest` exists because "a screen no role is
    // refused is a screen with no lock", and it cannot see this screen at all — so the sweep is
    // done here by hand, over the seeder's own role list rather than a copy of it.
    $roles = RoleMatrix::roles();

    expect($roles)->toContain('super_admin');
    expect(count($roles))->toBeGreaterThan(10, 'The role list looks truncated; this swept almost nothing.');

    $allowed = [];
    $refused = [];
    $other = [];

    foreach ($roles as $role) {
        // The session is flushed between roles. A session carried over from the previous actor
        // answers a REDIRECT for the next one, and a 302 is neither 200 nor 403 — it reads as "not
        // allowed in" and would let this sweep report a perfect lock while measuring nothing.
        // That is the documented `AuthenticateSession` trap from the role-matrix work.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $status = $this->actingAs(makeUser($role))->get('/horizon')->getStatusCode();

        match ($status) {
            200 => $allowed[] = $role,
            403 => $refused[] = $role,
            default => $other[] = "{$role} => {$status}",
        };
    }

    expect($other)->toBe([],
        'A role answered neither 200 nor 403, so this sweep did not measure access: '.implode(', ', $other)
    );

    expect($allowed)->toBe(['super_admin'],
        'Roles other than super_admin can open the queue dashboard: '.implode(', ', $allowed)
    );

    // The other direction, so a gate that refused EVERYBODY could not pass the assertion above.
    expect($refused)->toBe(
        array_values(array_diff($roles, ['super_admin'])),
        'Some role was neither allowed nor refused.'
    );
});

it('refuses a tenant login even on the default guard', function () {
    // The `instanceof User` clause. A TenantUser does not use spatie's HasRoles, so `hasRole()` on
    // one is a fatal rather than a false — this is what makes the refusal a refusal instead of a
    // 500. It normally authenticates on the `portal` guard and would never reach `$request->user()`
    // here at all; acting as one on the DEFAULT guard is what actually exercises the clause.
    $tenantUser = makeTenantUser(makeTenant());

    $this->actingAs($tenantUser)->get('/horizon')->assertForbidden();
});

it('lets a super admin in, and serves a dashboard rather than an empty shell', function () {
    $superAdmin = makeUser('super_admin');

    expect($superAdmin)->toBeInstanceOf(User::class);

    // `assertOk()` ALONE WOULD PASS ON A BLANK PAGE, and for a moment it did: Horizon used to ship
    // its dashboard as files you published into `public/vendor/horizon`, and this app had never run
    // the publish step. A 200 with no application in it is exactly what that looks like from a test.
    // As of v5 `Horizon::css()`/`js()` inline the bundle into the layout instead — which is why
    // there is no publish step in `deploy.sh` and `horizon:publish` now warns that it is obsolete —
    // so the mount point and the inlined bundle are what prove a working screen.
    $this->actingAs($superAdmin)->get('/horizon')
        ->assertOk()
        ->assertSee('id="horizon"', escape: false)
        ->assertSee('<script', escape: false);
});

it('stays shut on a workstation, where Horizon would open it to anyone', function () {
    // THE ENVIRONMENT IS SET TO `local` ON PURPOSE, and an earlier version of this test set it to a
    // nonsense value instead — which proved nothing, because a nonsense environment is not `local`
    // either and the refusal held for the wrong reason.
    //
    // Horizon's own `authorization()` reads `Gate::check(...) || app()->environment('local')`, and
    // its `Horizon::check()` fallback is `app()->environment('local')` on its own. This provider
    // drops both. Restoring either turns THIS assertion red and nothing else, which is the only
    // reason it is here.
    app()['env'] = 'local';

    $this->actingAs(makeUser('viewer'))->get('/horizon')->assertForbidden();
});
