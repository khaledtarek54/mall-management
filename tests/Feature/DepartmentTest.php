<?php

use App\Models\Department;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesPermissionsSeeder;

it('auto-generates a unique slug from the name', function () {
    $a = Department::create(['name' => 'Marketing']);
    $b = Department::create(['name' => 'Marketing']);

    expect($a->slug)->toBe('marketing');
    expect($b->slug)->toBe('marketing-2');
});

it('treats a department with no asset as global', function () {
    $global = Department::create(['name' => 'HR']);

    expect($global->isGlobal())->toBeTrue();
});

it('seeds the five core departments as global org units', function () {
    $this->seed(DepartmentSeeder::class);

    expect(Department::count())->toBe(5)
        ->and(Department::pluck('slug')->all())
        ->toContain('hr', 'marketing', 'accounting', 'leasing', 'operations')
        ->and(Department::whereNull('asset_id')->count())->toBe(5);
});

it('is idempotent — re-running the seeder does not duplicate', function () {
    $this->seed(DepartmentSeeder::class);
    $this->seed(DepartmentSeeder::class);

    expect(Department::count())->toBe(5);
});

it('registers department permissions and grants them by role', function () {
    $this->seed(RolesPermissionsSeeder::class);

    $admin = makeUser('super_admin');
    $manager = makeUser('manager');
    $viewer = makeUser('viewer');

    expect($admin->can('departments.delete'))->toBeTrue();

    expect($manager->can('departments.view'))->toBeTrue()
        ->and($manager->can('departments.create'))->toBeTrue()
        ->and($manager->can('departments.edit'))->toBeTrue()
        ->and($manager->can('departments.delete'))->toBeFalse();

    expect($viewer->can('departments.view'))->toBeTrue()
        ->and($viewer->can('departments.create'))->toBeFalse();
});
