<?php

/**
 * Regression — mobile §L L9 (their §9 #77). The person signed in is told whether they may act.
 *
 * Since 2026-09-05 the mobile token belongs to a PERSON, and `EnsurePortalAdminForWrites` refuses a
 * read-only one every write — but `/me` described only the company, and the login response discarded
 * the `user` it built, so the app offered every button to everyone and learned on the tap. `/me` now
 * carries `user: { name, email, isAdmin }` beside the company's keys.
 *
 * Two things the cases hold it to. It is the PERSON's, not the company's: two logins on one company
 * each see their own answer — a flag read off the company, or its first login, passes the one-login
 * cases and fails there. And it DESCRIBES the gate, never replaces it: the read-only case asserts the
 * write is still refused beside the flag that says so.
 */
it('tells a read-only login it may not act — and the write is still refused', function () {
    $tenant = makeTenant();
    $person = makeTenantUser($tenant, isAdmin: false);
    $headers = apiHeadersFor($person);

    $this->getJson('/api/v1/me', $headers)
        ->assertOk()
        ->assertJsonPath('data.user.isAdmin', false)
        ->assertJsonPath('data.user.email', $person->email)
        // The company's keys are untouched: the block sits beside them.
        ->assertJsonPath('data.email', $tenant->email);

    $this->patchJson('/api/v1/me', ['phone' => '+20 100 000 0000'], $headers)
        ->assertForbidden()
        ->assertJsonPath('error', 'read_only');
});

it('tells an admin login it may act', function () {
    $headers = apiHeadersFor(makeTenantUser(makeTenant(), isAdmin: true));

    $this->getJson('/api/v1/me', $headers)->assertOk()->assertJsonPath('data.user.isAdmin', true);
});

it('answers for the PERSON — two logins on one company each see their own', function () {
    $tenant = makeTenant();
    $admin = apiHeadersFor(makeTenantUser($tenant, isAdmin: true));
    $staff = apiHeadersFor(makeTenantUser($tenant, isAdmin: false));

    $this->getJson('/api/v1/me', $admin)->assertJsonPath('data.user.isAdmin', true);

    app('auth')->forgetGuards();

    $this->getJson('/api/v1/me', $staff)->assertJsonPath('data.user.isAdmin', false);
});

it('is on the `auth/me` alias too — one resource, both routes', function () {
    $headers = apiHeadersFor(makeTenantUser(makeTenant(), isAdmin: false));

    $this->getJson('/api/v1/auth/me', $headers)->assertOk()->assertJsonPath('data.user.isAdmin', false);
});
