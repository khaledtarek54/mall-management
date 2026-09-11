<?php

use App\Http\Middleware\EnsurePortalAdminForWrites;
use Illuminate\Support\Facades\Route;

/**
 * Regression — mobile §L (the docs list). A read-only login keeps the acts that are its OWN.
 *
 * `EnsurePortalAdminForWrites` gates every write by default and lets through the routes in its
 * `SELF_SCOPED` list — signing out, changing one's own password, one's own device, one's own
 * notifications. It matches them BY NAME, and nothing tested the list: a route renamed tomorrow would
 * drop out of it silently, and a read-only person could no longer sign themselves out — refused with a
 * 403 on the one act that exists to end a session. The list is checked against the route table, and
 * the act that matters most is driven for real.
 */
it('names only routes that exist — a rename cannot quietly make a personal act admin-only', function () {
    $missing = array_values(array_filter(
        EnsurePortalAdminForWrites::SELF_SCOPED,
        fn (string $name) => ! Route::has($name),
    ));

    expect(EnsurePortalAdminForWrites::SELF_SCOPED)->not->toBeEmpty()
        ->and($missing)->toBe([], 'SELF_SCOPED names routes that do not exist: '.implode(', ', $missing));
});

it('lets a read-only login sign itself out', function () {
    $token = makeTenantUser(makeTenant(), isAdmin: false)->createToken('phone', ['tenant:*'])->plainTextToken;

    $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$token])->assertOk();

    app('auth')->forgetGuards();

    // Signed out means the token is gone, not merely that the request was not refused.
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
});

it('still refuses that login a write that is the company\'s — the control', function () {
    $token = makeTenantUser(makeTenant(), isAdmin: false)->createToken('phone', ['tenant:*'])->plainTextToken;

    $this->postJson('/api/v1/me/requests', [], ['Authorization' => 'Bearer '.$token])
        ->assertForbidden()
        ->assertJsonPath('error', 'read_only');
});
