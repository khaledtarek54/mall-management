<?php

use App\Filament\Admin\Resources\Departments\DepartmentResource;
use App\Filament\Admin\Resources\Departments\Pages\ListDepartments;
use App\Models\Department;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
});

it('is not scoped to a property tenant (departments are operator-wide)', function () {
    expect(DepartmentResource::isScopedToTenant())->toBeFalse();
});

it('gates create/edit on the departments permission for a manager', function () {
    $this->actingAs(makeUser('manager'));

    $dept = Department::create(['name' => 'Marketing']);

    expect(DepartmentResource::canViewAny())->toBeTrue()
        ->and(DepartmentResource::canCreate())->toBeTrue()
        ->and(DepartmentResource::canEdit($dept))->toBeTrue()
        ->and(DepartmentResource::canDelete($dept))->toBeFalse();
});

it('denies create to a viewer', function () {
    $this->actingAs(makeUser('viewer'));

    expect(DepartmentResource::canViewAny())->toBeTrue()
        ->and(DepartmentResource::canCreate())->toBeFalse();
});

it('renders the departments list page without error', function () {
    ensureAllPropertiesAsset();
    $this->actingAs(makeUser('manager'));
    Filament::setTenant(makeAsset(['code' => 'HW']));

    Department::create(['name' => 'Operations']);

    Livewire::test(ListDepartments::class)
        ->assertOk()
        ->assertSee('Operations');
});
