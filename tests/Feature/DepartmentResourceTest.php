<?php

use App\Filament\Admin\Resources\Departments\DepartmentResource;
use App\Filament\Admin\Resources\Departments\Pages\CreateDepartment;
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

    // The set is no longer fixed (D-6). Delete still is: a department that routed a request is
    // referenced by rows an auditor reads, so deactivating is the retirement path.
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

it('actually lets a manager add a department, through the page', function () {
    // The gap this closes: `fd1ea2d1` removed `canCreate()`'s hard `false` and announced that a
    // department could be added, while registering no `create` route, no page and no button. The
    // permission granted nothing and the form's `disabledOn('edit')` branch was unreachable. A test
    // asserting `canCreate()` alone would have gone green over exactly that.
    ensureAllPropertiesAsset();
    $this->actingAs(makeUser('manager'));
    Filament::setTenant(makeAsset(['code' => 'DP']));

    // The button must point at a REAL ROUTE. Neither `assertActionExists('create')` nor
    // `Livewire::test(CreateDepartment::class)` proves that — both go green with the create route
    // deleted, because a Livewire component can be instantiated without one and a `CreateAction`
    // object exists whether or not it can navigate anywhere. Verified by mutation: removing the
    // route left both assertions passing, and this one throws.
    Livewire::test(ListDepartments::class)
        ->assertActionExists('create')
        ->assertActionHasUrl('create', DepartmentResource::getUrl('create'));

    Livewire::test(CreateDepartment::class)
        ->fillForm(['name' => 'Security', 'name_ar' => 'الأمن', 'is_active' => true, 'sort_order' => 0])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = Department::where('name', 'Security')->first();

    expect($created)->not->toBeNull()
        // Blank property means EVERY mall — the department a tenant request in any property can
        // route to, which is the whole reason the hybrid scope exists.
        ->and($created->asset_id)->toBeNull()
        ->and($created->slug)->not->toBeEmpty();

    Filament::setTenant(null, isQuiet: true);
});

it('refuses a viewer the create page', function () {
    $this->actingAs(makeUser('viewer'));

    expect(DepartmentResource::canCreate())->toBeFalse();
});
