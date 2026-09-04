<?php

use App\Models\Department;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Spatie\Permission\Models\Role;

/**
 * **A new department mints its own access role — it never adopts somebody else's.**
 *
 * A department's slug IS its spatie role name (`Department::roleName()`), and until now uniqueness
 * was asked of DEPARTMENTS only. So a department the operator named "Manager" took the slug
 * `manager`, the `created` hook's `Role::findOrCreate()` resolved to the EXISTING functional role
 * instead of minting an empty one — against a hook whose own comment says *"Created with NO
 * permissions on purpose"* — and `registerMember()` then ran `assignRole('manager')`.
 *
 * Measured on `mall_management_qa` (2026-09-04): `manager` carries **225 permissions**, and it is
 * one of `UserResource::PROTECTED_ROLES`, which a non-super_admin may not grant on the user form at
 * all. `departments.create` is held by `manager` and `mall_admin`, so an ordinary CRUD screen handed
 * out what the screen built for handing out access refuses.
 *
 * `Str::slug()` lands on a seeded role for six ordinary department names — Manager, Viewer, Owner,
 * Technician, Coordinator, Vendor. (`super_admin` and `customer_service` slug to `super-admin` and
 * `customer-service`, so they are out of reach.)
 *
 * The DETACH direction is the nastier half and is silent: `unregisterMember()` ran
 * `removeRole('manager')`, stripping the real role from an account that held it in its own right.
 *
 * What is deliberately NOT changed: the five seeded core departments still adopt their own roles —
 * `Department::ADOPTABLE_ROLES` — which is what makes `accounting` mean one thing on the org chart
 * and on the roles matrix. Everything else is refused by default, so a fifteenth functional role, or
 * a custom one the operator builds, is out of reach the day it is created.
 */
beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

it('does not hand out the manager role because a department was called Manager', function () {
    $department = Department::create(['name' => 'Manager']);
    $user = makeUser('viewer');

    $department->registerMember($user);

    expect($department->slug)->not->toBe('manager')
        ->and($user->fresh()->hasRole('manager'))->toBeFalse()
        // `invoices.create` is the concrete thing `manager` holds and `viewer` does not.
        ->and($user->fresh()->can('invoices.create'))->toBeFalse()
        // The role the department DID mint is the empty scope marker the `created` hook intends.
        ->and(Role::findByName($department->slug, 'web')->permissions)->toHaveCount(0);
});

it('does not strip a real manager role when somebody leaves a department called Manager', function () {
    // The revoke direction, and the one nothing would have reported: the user keeps their seat on
    // the department pivot or loses it, but their ACCESS must not move.
    $department = Department::create(['name' => 'Manager']);
    $user = makeUser('manager');

    $department->registerMember($user);
    $department->unregisterMember($user);

    expect($user->fresh()->hasRole('manager'))->toBeTrue()
        ->and($user->fresh()->can('invoices.create'))->toBeTrue();
});

it('will not adopt a custom role the operator built either', function () {
    // Asked of the roles TABLE, not of a list of the fourteen seeded names.
    Role::findOrCreate('night-shift', 'web')->givePermissionTo('invoices.create');

    $department = Department::create(['name' => 'Night Shift']);
    $user = makeUser('viewer');

    $department->registerMember($user);

    expect($department->slug)->toBe('night-shift-2')
        ->and($user->fresh()->can('invoices.create'))->toBeFalse();
});

it('still lets a department named after one of the five adopt its seeded role', function () {
    // The control that matters most: reuse is the documented intent for these five, and a blanket
    // "never land on an existing role" rule would have destroyed it.
    $department = Department::create(['name' => 'Accounting']);
    $user = makeUser('viewer');

    $department->registerMember($user);

    expect($department->slug)->toBe('accounting')
        ->and($user->fresh()->hasRole('accounting'))->toBeTrue()
        ->and($user->fresh()->can('invoices.create'))->toBeTrue();
});

it('leaves a department whose name collides with nothing alone', function () {
    expect(Department::create(['name' => 'Security'])->slug)->toBe('security');
});

it('names exactly the seeded core departments as the roles a department may adopt', function () {
    // ADOPTABLE_ROLES is a list, and this is what stops it rotting: it must be exactly the slugs
    // DepartmentSeeder lays down, so adding a sixth core department turns this red rather than
    // quietly leaving it unable to adopt its own role.
    $this->seed(DepartmentSeeder::class);

    expect(Department::query()->orderBy('slug')->pluck('slug')->all())
        ->toBe(collect(Department::ADOPTABLE_ROLES)->sort()->values()->all());
});
