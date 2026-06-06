<?php

beforeEach(function () {
    seedRoles();
    ensureAllPropertiesAsset();
});

/**
 * Regression for the Profile 500. The admin panel has tenancy, but the profile
 * route (`/admin/profile`) is NOT tenant-scoped, so Filament::getTenant() is
 * null there. With the full-chrome profile layout the tenant menu renders and
 * calls getTenantName(null) → TypeError. The simple profile layout has no
 * tenant menu, so it renders cleanly.
 */
it('serves /admin/profile without a tenant in context (no 500)', function () {
    // A manager is not subject to forced-2FA setup (super_admin only), so the
    // request reaches the profile render — exactly where the crash happened.
    $this->actingAs(makeUser('manager'));

    $this->get('/admin/profile')->assertSuccessful();
});

it('serves /admin/profile for a super admin too', function () {
    $this->actingAs(makeUser('super_admin'));

    $this->get('/admin/profile')->assertSuccessful();
});
