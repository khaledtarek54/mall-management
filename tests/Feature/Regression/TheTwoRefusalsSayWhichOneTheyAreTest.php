<?php

/**
 * Regression — mobile §L L14. The two 403s say which one they are.
 *
 * `EnsureTenantActive` (the COMPANY is blocked: the token is destroyed, go to the Blocked screen) and
 * `EnsurePortalAdminForWrites` (this PERSON may read but not act: the session is fine) answered the same
 * bare `{message, statusCode: 403}`. The message is translated, and a client must never branch on
 * prose, so the app told them apart by guessing from the HTTP method and whether the token survived —
 * and its brief told it every 403 meant "wipe the token", which signs a read-only person out for
 * tapping a button. Each now carries `error`, the key the Paymob endpoints already use.
 *
 * The bodies are asserted EXACTLY, after the response middleware, so a renamed key or a second key
 * shows. Each code is paired with what must happen to the token, because that is the whole difference
 * between them.
 */
it('codes a read-only login\'s refused write `read_only` — and leaves its session alone', function () {
    $headers = apiHeadersFor(makeTenantUser(makeTenant(), isAdmin: false));

    $this->patchJson('/api/v1/me', ['phone' => '+20 100 000 0000'], $headers)
        ->assertForbidden()
        ->assertExactJson(['message' => __('auth.read_only'), 'statusCode' => 403, 'error' => 'read_only']);

    app('auth')->forgetGuards();

    // The refusal was about the button, not the person: the same token still reads.
    $this->getJson('/api/v1/me', $headers)->assertOk();
});

it('codes a company blocked mid-session `tenant_inactive` — and destroys the token', function () {
    $tenant = makeTenant();
    $headers = apiHeadersFor(makeTenantUser($tenant, isAdmin: false));
    $tenant->update(['status' => 'blacklisted']);

    $this->getJson('/api/v1/me', $headers)
        ->assertForbidden()
        ->assertExactJson(['message' => __('auth.account_blocked'), 'statusCode' => 403, 'error' => 'tenant_inactive']);

    // The guard caches who it resolved for the life of the test app; a real next request would not.
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/me', $headers)->assertUnauthorized();
});

it('codes a soft-deleted company the same way — its staff\'s logins still resolve, the company does not', function () {
    $tenant = makeTenant();
    $headers = apiHeadersFor(makeTenantUser($tenant, isAdmin: false));
    $tenant->delete();

    $this->getJson('/api/v1/me', $headers)
        ->assertForbidden()
        ->assertJsonPath('error', 'tenant_inactive');
});

it('codes a blocked company\'s sign-in `tenant_inactive` too — one condition, both doors', function () {
    $tenant = makeTenant(['status' => 'blacklisted']);
    $user = makeTenantUser($tenant);

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'phone'])
        ->assertForbidden()
        ->assertJsonPath('error', 'tenant_inactive')
        ->assertJsonPath('statusCode', 403);
});

it('leaves an uncoded refusal without an `error` key — a 404 names nothing', function () {
    $tenant = makeTenant();

    $this->getJson('/api/v1/me/invoices/999999', apiHeaders($tenant))
        ->assertNotFound()
        ->assertJsonMissingPath('error');
});
