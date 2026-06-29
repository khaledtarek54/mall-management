<?php

use App\Models\TenantUser;
use App\Support\Portal;

it('resolves the portal user, tenant, tenant_id and admin flag for an authenticated admin', function () {
    $tenant = makeTenant();
    $user = makeTenantUser($tenant, isAdmin: true);

    $this->actingAs($user, 'portal');

    expect(Portal::user())->toBeInstanceOf(TenantUser::class)
        ->and(Portal::user()->is($user))->toBeTrue()
        ->and(Portal::tenant())->not->toBeNull()
        ->and(Portal::tenant()->is($tenant))->toBeTrue()
        ->and(Portal::tenantId())->toBe($tenant->id)
        ->and(Portal::isAdmin())->toBeTrue();
});

it('reports a non-admin portal user as read-only while still resolving tenant context', function () {
    $tenant = makeTenant();
    $user = makeTenantUser($tenant, isAdmin: false);

    $this->actingAs($user, 'portal');

    // Tenant scoping still resolves for read-only users...
    expect(Portal::user()->is($user))->toBeTrue()
        ->and(Portal::tenantId())->toBe($tenant->id)
        ->and(Portal::tenant()->is($tenant))->toBeTrue()
        // ...but they may not write.
        ->and(Portal::isAdmin())->toBeFalse();
});

it('returns null/false when nobody is authenticated on the portal guard', function () {
    expect(Portal::user())->toBeNull()
        ->and(Portal::tenant())->toBeNull()
        ->and(Portal::tenantId())->toBeNull()
        ->and(Portal::isAdmin())->toBeFalse();
});

it('does not leak a web-guard user into the portal context', function () {
    // A super_admin authenticated on the default (web) guard must not be seen
    // by the portal helpers — the portal scopes strictly to the 'portal' guard.
    $this->actingAs(makeUser('super_admin'));

    expect(Portal::user())->toBeNull()
        ->and(Portal::tenant())->toBeNull()
        ->and(Portal::tenantId())->toBeNull()
        ->and(Portal::isAdmin())->toBeFalse();
});

it('scopes tenantId to the authenticated user even when other tenants exist', function () {
    // Guard against returning the wrong tenant_id when multiple tenants/users exist.
    $other = makeTenant();
    makeTenantUser($other, isAdmin: true);

    $mine = makeTenant();
    $user = makeTenantUser($mine, isAdmin: true);

    $this->actingAs($user, 'portal');

    expect(Portal::tenantId())->toBe($mine->id)
        ->and(Portal::tenantId())->not->toBe($other->id)
        ->and(Portal::tenant()->is($mine))->toBeTrue();
});
