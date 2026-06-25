<?php

use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
});

function flatPermissions(): array
{
    return collect(RolesPermissionsSeeder::PERMISSIONS)
        ->flatMap(fn (array $group) => array_keys($group))
        ->all();
}

it('super_admin holds every seeded permission', function () {
    $admin = makeUser('super_admin');
    $admin->syncRoles(['super_admin']);

    $missing = collect(flatPermissions())->reject(fn ($p) => $admin->can($p))->values()->all();
    expect($missing)->toBe([]);
});

it('viewer can view but not mutate', function () {
    $viewer = makeUser('viewer');
    $viewer->syncRoles(['viewer']);

    expect($viewer->can('invoices.view'))->toBeTrue();
    expect($viewer->can('invoices.create'))->toBeFalse();
    expect($viewer->can('invoices.delete'))->toBeFalse();
});

it('manager can create + edit operations but not delete or touch settings', function () {
    $manager = makeUser('manager');
    $manager->syncRoles(['manager']);

    expect($manager->can('invoices.create'))->toBeTrue();
    expect($manager->can('leases.edit'))->toBeTrue();
    expect($manager->can('invoices.delete'))->toBeFalse();
    expect($manager->can('settings.edit'))->toBeFalse();
});

it('leasing is scoped to leases + tenants modules', function () {
    $lm = makeUser('leasing');
    $lm->syncRoles(['leasing']);

    expect($lm->can('leases.create'))->toBeTrue();
    expect($lm->can('tenants.create'))->toBeTrue();
    expect($lm->can('cam.create'))->toBeFalse();
    expect($lm->can('maintenance.assign'))->toBeFalse();
});

it('operations is scoped to maintenance', function () {
    $mm = makeUser('operations');
    $mm->syncRoles(['operations']);

    expect($mm->can('maintenance.create'))->toBeTrue();
    expect($mm->can('maintenance.edit'))->toBeTrue();
    expect($mm->can('maintenance.assign'))->toBeTrue();
    expect($mm->can('invoices.create'))->toBeFalse();
});
