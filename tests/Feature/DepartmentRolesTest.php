<?php

use App\Filament\Admin\Resources\Departments\DepartmentResource;
use App\Models\Department;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

it('maps each department to its access role', function () {
    expect((new Department(['slug' => 'leasing']))->roleName())->toBe('leasing')
        ->and((new Department(['slug' => 'operations']))->roleName())->toBe('operations')
        ->and((new Department(['slug' => 'accounting']))->roleName())->toBe('accounting')
        ->and((new Department(['slug' => 'marketing']))->roleName())->toBe('marketing')
        ->and((new Department(['slug' => 'hr']))->roleName())->toBe('hr');
});

it('seeds the department access roles with their permissions', function () {
    $accounting = makeUser('accounting');
    expect($accounting->can('invoices.create'))->toBeTrue()
        ->and($accounting->can('payments.view'))->toBeTrue()
        ->and($accounting->can('requests.create'))->toBeFalse();

    $marketing = makeUser('marketing');
    expect($marketing->can('marketing.create'))->toBeTrue()
        ->and($marketing->can('invoices.create'))->toBeFalse();

    $hr = makeUser('hr');
    expect($hr->can('users.create'))->toBeTrue()
        ->and($hr->can('leases.view'))->toBeFalse();
});

it('registering a user into a department grants the department role', function () {
    $dept = Department::create(['name' => 'Accounting']); // slug: accounting
    $user = makeUser('viewer');

    $dept->registerMember($user);

    expect($user->fresh()->hasRole('accounting'))->toBeTrue()
        ->and($dept->members()->whereKey($user->id)->exists())->toBeTrue();
});

it('unregistering a user removes the department role and membership', function () {
    $dept = Department::create(['name' => 'Marketing']); // slug: marketing
    $user = makeUser('viewer');
    $dept->registerMember($user);

    $dept->unregisterMember($user);

    expect($user->fresh()->hasRole('marketing'))->toBeFalse()
        ->and($dept->members()->whereKey($user->id)->exists())->toBeFalse();
});

it('refuses to DELETE a department, for everyone — create is now permission-gated', function () {
    // Deletion stays refused project-wide; only the create freeze was lifted (D-6). Without an
    // authenticated user `canCreate()` is false anyway, so this case says nothing about it — the
    // create half is asserted per role in DepartmentScenarioTest.
    expect(DepartmentResource::canDeleteAny())->toBeFalse()
        ->and(DepartmentResource::canDelete(new Department(['name' => 'x'])))->toBeFalse();
});
