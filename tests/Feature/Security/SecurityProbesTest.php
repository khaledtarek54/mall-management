<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Plan 2 (QA) — security probes. Cross-tenant 404 isolation + Paymob HMAC are
 * already covered per-endpoint; this fills the structural gaps: that auth is
 * enforced on EVERY protected route (future-proof), that the abuse throttles
 * actually fire, and that a tenant can neither spoof ownership nor mass-assign
 * privileged fields through the create endpoints.
 */

// ---------------------------------------------------------------------------
// 1. Auth-guard matrix — every /api/v1 route except the public auth endpoints
//    must sit behind auth:tenant-api. Catches a future endpoint that forgets it.
// ---------------------------------------------------------------------------
it('guards every non-public /api/v1 route with the tenant-api auth middleware', function () {
    $public = ['api/v1/auth/login', 'api/v1/auth/forgot-password', 'api/v1/auth/reset-password'];

    $unguarded = [];
    foreach (Route::getRoutes() as $route) {
        if (! Str::startsWith($route->uri(), 'api/v1/')) {
            continue;
        }
        if (in_array($route->uri(), $public, true)) {
            continue;
        }
        // The shopper feed (module 36) is unauthenticated BY DESIGN — a visitor standing in the
        // mall has no account. It is not exempt from scrutiny, only from this particular check:
        // the test below replaces the guarantee with the one that actually applies to it.
        if (Str::startsWith($route->uri(), 'api/v1/public/')) {
            continue;
        }
        $mw = collect($route->gatherMiddleware());
        if (! $mw->contains(fn ($m) => Str::contains($m, 'auth:tenant-api'))) {
            $unguarded[] = $route->methods()[0] . ' ' . $route->uri();
        }
    }

    expect($unguarded)->toBe([], 'Unguarded /api/v1 routes (missing auth:tenant-api): ' . implode(', ', $unguarded));
});

/**
 * The replacement guarantee for `/api/v1/public/*`.
 *
 * Skipping the auth check above would otherwise be a hole big enough to drive a whole new
 * endpoint through: anything a future author puts under that prefix inherits "no auth required"
 * and nothing asks another question. So the prefix carries its own, stricter contract —
 * **every** route on it must be rate-limited (it is reachable by the entire internet) and must be
 * behind the module flag (an operator who switches the shopper feed off means it, and there is no
 * user here for a permission to gate).
 *
 * A route added to this prefix without both fails here, which is the point.
 */
it('constrains every unauthenticated /api/v1/public route with a throttle and the module gate', function () {
    $publicRoutes = collect(Route::getRoutes())
        ->filter(fn ($route) => Str::startsWith($route->uri(), 'api/v1/public/'));

    // A guard that silently matches nothing is worse than no guard.
    expect($publicRoutes)->not->toBeEmpty('The public shopper-feed routes were not found — has the prefix moved?');

    $unconstrained = [];
    foreach ($publicRoutes as $route) {
        $mw = collect($route->gatherMiddleware());

        $throttled = $mw->contains(fn ($m) => Str::startsWith($m, 'throttle:'));
        $moduleGated = $mw->contains(\App\Http\Middleware\EnsureMarketingPostsEnabled::class);

        if (! $throttled || ! $moduleGated) {
            $unconstrained[] = $route->methods()[0].' '.$route->uri()
                .(! $throttled ? ' [no throttle]' : '')
                .(! $moduleGated ? ' [no module gate]' : '');
        }
    }

    expect($unconstrained)->toBe([], 'Unconstrained public routes: '.implode(', ', $unconstrained));
});

it('serves the public shopper feed with no credentials, and still 401s the tenant API', function () {
    // The two halves of the same fact, asserted together so neither can quietly become the other:
    // the public surface really is reachable unauthenticated (otherwise the exemption above is
    // protecting nothing), and the authenticated surface really is not.
    $this->getJson('/api/v1/public/malls')->assertOk();
    $this->getJson('/api/v1/me/invoices')->assertUnauthorized();
});

it('rejects an unauthenticated request to a protected endpoint with 401', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
    $this->getJson('/api/v1/me/invoices')->assertUnauthorized();
    $this->postJson('/api/v1/me/requests', [])->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// 2. Abuse throttles actually fire.
// ---------------------------------------------------------------------------
it('throttles repeated login attempts (5/min)', function () {
    $payload = ['email' => 'nobody@atriomwalk.test', 'password' => 'wrong', 'device_name' => 'probe'];

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/login', $payload)->assertStatus(401); // bad creds, not yet throttled
    }

    $this->postJson('/api/v1/auth/login', $payload)->assertStatus(429);
});

it('throttles repeated password-reset requests (3/min)', function () {
    $payload = ['email' => 'nobody@atriomwalk.test'];

    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/v1/auth/forgot-password', $payload);
    }

    $this->postJson('/api/v1/auth/forgot-password', $payload)->assertStatus(429);
});

// ---------------------------------------------------------------------------
// 3. Ownership cannot be spoofed; privileged fields cannot be mass-assigned.
// ---------------------------------------------------------------------------
it('ignores a client-supplied tenant_id — the request belongs to the caller', function () {
    $caller = makeTenant();
    makeLease(makeUnit(makeAsset()), $caller);
    $victim = makeTenant();

    $this->postJson('/api/v1/me/requests', [
        'title' => 'Spoof attempt',
        'description' => 'Trying to file against another tenant.',
        'category' => 'electrical',
        'tenant_id' => $victim->id,   // attacker-controlled — must be ignored
    ], apiHeaders($caller))->assertCreated();

    $this->assertDatabaseHas('tenant_requests', ['title' => 'Spoof attempt', 'tenant_id' => $caller->id]);
    $this->assertDatabaseMissing('tenant_requests', ['title' => 'Spoof attempt', 'tenant_id' => $victim->id]);
});

it('ignores client-supplied status/csat on create (no privilege mass-assignment)', function () {
    $caller = makeTenant();
    makeLease(makeUnit(makeAsset()), $caller);

    $response = $this->postJson('/api/v1/me/requests', [
        'title' => 'Mass-assign attempt',
        'description' => 'Trying to open it pre-resolved + pre-rated.',
        'category' => 'plumbing',
        'status' => 'closed',     // must not be honoured
        'csat_rating' => 5,        // must not be honoured
    ], apiHeaders($caller))->assertCreated();

    expect($response->json('data.status'))->toBe('submitted');

    $this->assertDatabaseHas('tenant_requests', [
        'title' => 'Mass-assign attempt', 'status' => 'submitted', 'csat_rating' => null,
    ]);
});
