<?php

use App\Filament\Admin\RelationManagers\DepartmentMembersRelationManager;
use App\Filament\Admin\Resources\Departments\Pages\EditDepartment;
use App\Models\Department;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('attaches staff to a department with pivot data', function () {
    $dept = Department::create(['name' => 'Operations']);
    $user = makeUser('manager');

    $dept->members()->attach($user->id, ['role' => 'Site Engineer', 'assigned_at' => now()]);

    expect($dept->members)->toHaveCount(1)
        ->and($dept->members->first()->id)->toBe($user->id)
        ->and($dept->members->first()->pivot->role)->toBe('Site Engineer');
});

it('exposes the inverse relation from the user', function () {
    $dept = Department::create(['name' => 'Marketing']);
    $user = makeUser('manager');

    $user->departments()->attach($dept->id);

    expect($user->departments->pluck('slug')->all())->toContain('marketing');
});

it('cascades the pivot when a department is hard-deleted', function () {
    $dept = Department::create(['name' => 'Leasing']);
    $user = makeUser('manager');
    $dept->members()->attach($user->id);
    $deptId = $dept->id;

    $dept->forceDelete();

    expect(DB::table('department_user')->where('department_id', $deptId)->count())->toBe(0);
});

it('renders the department members relation manager', function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant(makeAsset(['code' => 'HW']));

    $dept = Department::create(['name' => 'Operations']);

    Livewire::test(DepartmentMembersRelationManager::class, [
        'ownerRecord' => $dept,
        'pageClass' => EditDepartment::class,
    ])->assertOk();
});
