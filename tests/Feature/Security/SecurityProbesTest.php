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
        $mw = collect($route->gatherMiddleware());
        if (! $mw->contains(fn ($m) => Str::contains($m, 'auth:tenant-api'))) {
            $unguarded[] = $route->methods()[0] . ' ' . $route->uri();
        }
    }

    expect($unguarded)->toBe([], 'Unguarded /api/v1 routes (missing auth:tenant-api): ' . implode(', ', $unguarded));
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
