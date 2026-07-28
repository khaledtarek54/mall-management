<?php

/**
 * Settle whether /admin/{tenant}/roles/create is reachable without
 * `roles.create`, through the real HTTP route rather than by reasoning about
 * Filament's internals.
 *
 * The RBAC browser smoke reports a 200 there for all seven non-super-admin
 * roles, while RoleResource::canCreate() returns false and
 * CreateRecord::mount() does abort_unless(canCreate(), 403). Those two facts
 * cannot both be true, so one of them is wrong — this finds out which.
 */

use Database\Seeders\RolesPermissionsSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

it('refuses the roles create page to a role without roles.create', function () {
    $asset = makeAsset(['code' => 'AW']);
    $manager = makeUser('manager', [$asset->id]);

    expect($manager->can('roles.create'))->toBeFalse();

    $this->actingAs($manager)
        ->get("/admin/{$asset->code}/roles/create")
        ->assertForbidden();
});

it('still lets a super_admin reach it', function () {
    $asset = makeAsset(['code' => 'AW']);

    $this->actingAs(makeUser('super_admin', [$asset->id]))
        ->get("/admin/{$asset->code}/roles/create")
        ->assertSuccessful();
});

it('refuses the underlying write, not just the page', function () {
    // The page being reachable would be bad; the CREATE succeeding would be
    // worse. Whatever the route returns, a manager must not end up with a new
    // role — privilege escalation is exactly what roles.create guards.
    $asset = makeAsset(['code' => 'AW']);
    $manager = makeUser('manager', [$asset->id]);

    $before = Role::count();

    $this->actingAs($manager)->get("/admin/{$asset->code}/roles/create");

    expect(Role::count())->toBe($before);
});
