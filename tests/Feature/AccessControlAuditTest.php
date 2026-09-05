<?php

use App\Filament\Admin\RelationManagers\DepartmentMembersRelationManager;
use App\Filament\Admin\Resources\Departments\Pages\EditDepartment;
use App\Filament\Admin\Resources\Roles\Pages\CreateRole;
use App\Filament\Admin\Resources\Roles\Pages\EditRole;
use App\Filament\Admin\Resources\Roles\Pages\ListRoles;
use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Department;
use App\Models\User;
use App\Support\ActivityLogChangeRenderer;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/**
 * Access-control audit trail. Auditing is delta-based at each UI mutation point
 * (User pages, Department membership, Role pages) — NOT via spatie events, which
 * miss the primary path (Filament's roles Select saves through the raw pivot).
 * These tests drive the real UI paths, not direct assignRole() calls.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class); // roles + permissions; runs with no causer
});

function acAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('super_admin'); // silent: no auth context yet, events disabled

    return $admin;
}

/** Set the Filament admin tenant — call AFTER actingAs (the TenantSet event needs the user). */
function acTenant(): void
{
    Filament::setTenant(makeAsset(['code' => 'ACX']));
}

function acRows(int $subjectId)
{
    return Activity::where('log_name', 'access_control')->where('subject_id', $subjectId)->get();
}

function acField($row, string $field)
{
    // Read attribute_changes — the column AccessControlAudit writes (via
    // withChanges) and the column ActivityLogChangeRenderer / the UI read.
    return data_get($row?->attribute_changes, "attributes.{$field}");
}

/*
| ---- User form: the primary role-grant path (was unaudited) ------------- |
*/

it('audits a role grant made through the User edit form', function () {
    $admin = acAdmin();
    $this->actingAs($admin);

    $target = User::factory()->create();
    $target->assignRole('viewer'); // baseline, silent

    acTenant();

    Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->fillForm(['roles' => [
            Role::findByName('viewer', 'web')->id,
            Role::findByName('leasing', 'web')->id,
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    $granted = acRows($target->id)->firstWhere(fn ($a) => acField($a, 'role_granted'));

    expect($granted)->not->toBeNull()
        ->and($granted->causer_id)->toBe($admin->id)
        ->and(acField($granted, 'role_granted'))->toBe('leasing');
});

it('audits a role revoke made through the User edit form', function () {
    $admin = acAdmin();
    $this->actingAs($admin);

    $target = User::factory()->create();
    $target->syncRoles(['viewer', 'leasing']); // silent baseline

    acTenant();

    Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->fillForm(['roles' => [Role::findByName('viewer', 'web')->id]]) // drop leasing
        ->call('save')
        ->assertHasNoFormErrors();

    $revoked = acRows($target->id)->firstWhere(fn ($a) => acField($a, 'role_revoked'));

    expect(acField($revoked, 'role_revoked'))->toBe('leasing');
});

it('writes no audit row when the User form is saved without changing roles', function () {
    $admin = acAdmin();
    $this->actingAs($admin);

    $target = User::factory()->create();
    $target->assignRole('viewer');

    acTenant();

    Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->fillForm(['roles' => [Role::findByName('viewer', 'web')->id]]) // unchanged
        ->call('save')
        ->assertHasNoFormErrors();

    expect(acRows($target->id))->toHaveCount(0);
});

it('audits roles granted when a user is created via the form', function () {
    $this->actingAs(acAdmin());

    acTenant();

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'New Staff',
            'email' => 'new.staff@mall.test',
            'password' => 'password',
            'roles' => [Role::findByName('leasing', 'web')->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'new.staff@mall.test')->firstOrFail();

    expect(acField(acRows($user->id)->first(), 'role_granted'))->toBe('leasing');
});

it('lists every granted role name in one entry', function () {
    $admin = acAdmin();
    $this->actingAs($admin);
    $target = User::factory()->create();

    acTenant();

    Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->fillForm(['roles' => [
            Role::findByName('leasing', 'web')->id,
            Role::findByName('operations', 'web')->id,
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    $granted = acField(acRows($target->id)->firstWhere(fn ($a) => acField($a, 'role_granted')), 'role_granted');

    expect($granted)->toContain('leasing')->toContain('operations');
});

/*
| ---- Privilege-escalation guard: only super_admin grants super_admin ---- |
*/

it('blocks a non-super_admin from granting super_admin through the User form', function () {
    $manager = User::factory()->create();
    $manager->assignRole('manager'); // holds users.* but is not super_admin
    $this->actingAs($manager);
    acTenant();

    $target = User::factory()->create();
    $target->assignRole('viewer');

    Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->fillForm(['roles' => [
            Role::findByName('viewer', 'web')->id,
            Role::findByName('super_admin', 'web')->id, // attempt to escalate
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->hasRole('super_admin'))->toBeFalse()
        ->and($target->fresh()->hasRole('viewer'))->toBeTrue()
        // the blocked attempt is itself recorded
        ->and(acField(acRows($target->id)->firstWhere(fn ($a) => acField($a, 'protected_role_change_blocked')), 'protected_role_change_blocked'))
        ->toContain('attempted grant: super_admin');
});

it('blocks a non-super_admin from granting the manager role through the User form', function () {
    $hr = User::factory()->create();
    $hr->assignRole('hr'); // holds users.* but not roles.edit, and is not super_admin
    $this->actingAs($hr);
    acTenant();

    $target = User::factory()->create();
    $target->assignRole('viewer');

    Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->fillForm(['roles' => [
            Role::findByName('viewer', 'web')->id,
            Role::findByName('manager', 'web')->id, // attempt to confer near-total power
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->hasRole('manager'))->toBeFalse()
        ->and($target->fresh()->hasRole('viewer'))->toBeTrue()
        ->and(acField(acRows($target->id)->firstWhere(fn ($a) => acField($a, 'protected_role_change_blocked')), 'protected_role_change_blocked'))
        ->toContain('attempted grant: manager');
});

it('routes the Users table edit + header create to the guarded pages (no modal bypass)', function () {
    $this->actingAs(acAdmin());
    acTenant();
    $target = User::factory()->create();

    // A URL action carries no modal form, so it cannot save an escalation — every
    // create/edit must go through the page (where enforcement + audit run).
    Livewire::test(ListUsers::class)
        ->assertTableActionHasUrl('edit', UserResource::getUrl('edit', ['record' => $target]), $target)
        ->assertActionHasUrl('create', UserResource::getUrl('create'));
});

it('routes the Roles table edit to the guarded page', function () {
    $this->actingAs(acAdmin());
    acTenant();
    $role = Role::create(['name' => 'custom_route', 'guard_name' => 'web']);

    Livewire::test(ListRoles::class)
        ->assertTableActionHasUrl('edit', RoleResource::getUrl('edit', ['record' => $role]), $role)
        ->assertActionHasUrl('create', RoleResource::getUrl('create'));
});

it('renders access-control entries in the Activity Log (not as a dash)', function () {
    $this->actingAs(acAdmin());
    acTenant();
    $target = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->fillForm(['roles' => [Role::findByName('leasing', 'web')->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    $row = acRows($target->id)->firstWhere(fn ($a) => acField($a, 'role_granted'));
    $html = app(ActivityLogChangeRenderer::class)->render($row);

    expect($html)->toContain('leasing')->not->toContain('—');
});

it('lets a super_admin grant super_admin through the User form', function () {
    $admin = acAdmin();
    $this->actingAs($admin);
    acTenant();

    $target = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->fillForm(['roles' => [Role::findByName('super_admin', 'web')->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->hasRole('super_admin'))->toBeTrue();
});

it('a non-super_admin cannot revoke an existing super_admin through the User form', function () {
    $manager = User::factory()->create();
    $manager->assignRole('manager');
    $this->actingAs($manager);
    acTenant();

    $target = User::factory()->create();
    $target->syncRoles(['super_admin', 'viewer']);

    // manager drops super_admin from the set — enforcement re-adds it.
    Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->fillForm(['roles' => [Role::findByName('viewer', 'web')->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->hasRole('super_admin'))->toBeTrue();
});

/*
| ---- Department membership path ----------------------------------------- |
*/

it('audits role grants made through department membership', function () {
    $this->actingAs(acAdmin());
    $dept = Department::create(['name' => 'Leasing']); // slug = leasing
    $user = User::factory()->create();

    $dept->registerMember($user);

    expect(acField(acRows($user->id)->first(), 'role_granted'))->toBe('leasing');
});

it('does not re-log grants for members who already hold the department role', function () {
    $this->actingAs(acAdmin());
    $dept = Department::create(['name' => 'Operations']);
    $dept->registerMember(User::factory()->create());
    $dept->registerMember(User::factory()->create());

    $before = Activity::where('log_name', 'access_control')->count();
    $dept->assignRolesToMembers(); // re-run over the whole roster — nothing changed

    expect(Activity::where('log_name', 'access_control')->count())->toBe($before);
});

it('audits role revoke when a member is removed from a department', function () {
    $this->actingAs(acAdmin());
    $dept = Department::create(['name' => 'Leasing']);
    $user = User::factory()->create();
    $dept->registerMember($user);

    $dept->unregisterMember($user);

    expect(acField(acRows($user->id)->firstWhere(fn ($a) => acField($a, 'role_revoked')), 'role_revoked'))
        ->toBe('leasing');
});

it('audits dept membership grant + revoke through the relation-manager attach/detach actions', function () {
    $this->actingAs(acAdmin());
    acTenant();
    $dept = Department::create(['name' => 'Leasing']); // slug = leasing
    // ADMIN-PANEL STAFF, because that is who the picker offers. `AttachAction`'s
    // `recordSelectOptionsQuery` narrows to users holding a non-`owner` role, and Filament derives
    // the Select's `Rule::in` from that query — so a role-less `User::factory()` row is refused as
    // *"The selected user is invalid"*, nothing is attached, and no exception is thrown. The
    // sibling test above passes because it calls `registerMember()` on the MODEL and never touches
    // the picker; this one is here precisely to drive the relation manager.
    $target = User::factory()->create();
    $target->assignRole('viewer');

    // Attach action -> ->after assignRolesToMembers grants the dept role + audits.
    Livewire::test(DepartmentMembersRelationManager::class, [
        'ownerRecord' => $dept,
        'pageClass' => EditDepartment::class,
    ])
        ->mountTableAction('attach')
        ->setTableActionData(['recordId' => $target->id])
        ->callMountedTableAction();

    expect($target->fresh()->hasRole('leasing'))->toBeTrue()
        ->and(acField(acRows($target->id)->firstWhere(fn ($a) => acField($a, 'role_granted')), 'role_granted'))
        ->toBe('leasing');

    // Detach action -> ->after unregisterMember revokes + audits.
    Livewire::test(DepartmentMembersRelationManager::class, [
        'ownerRecord' => $dept->fresh(),
        'pageClass' => EditDepartment::class,
    ])->callTableAction('detach', $target);

    expect($target->fresh()->hasRole('leasing'))->toBeFalse()
        ->and(acField(acRows($target->id)->firstWhere(fn ($a) => acField($a, 'role_revoked')), 'role_revoked'))
        ->toBe('leasing');
});

/*
| ---- Role pages: permission diff (incl. the revoke path) + deletion ----- |
*/

it('audits permissions granted when a role is created via the form', function () {
    $this->actingAs(acAdmin());
    acTenant();

    Livewire::test(CreateRole::class)
        ->fillForm([
            'name' => 'new_audit_role',
            'permissions_module_invoices' => ['invoices.view'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = Role::findByName('new_audit_role', 'web');

    expect(acField(acRows($role->id)->first(), 'permission_granted'))->toContain('invoices.view');
});

it('audits both the permission grant and revoke through the Role edit form', function () {
    $this->actingAs(acAdmin());
    $role = Role::create(['name' => 'custom_audit', 'guard_name' => 'web']);
    $role->givePermissionTo('invoices.view'); // baseline, silent

    acTenant();

    Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->fillForm(['permissions_module_invoices' => ['invoices.create']]) // remove view, add create
        ->call('save')
        ->assertHasNoFormErrors();

    $rows = acRows($role->id);

    expect(acField($rows->firstWhere(fn ($a) => acField($a, 'permission_granted')), 'permission_granted'))
        ->toContain('invoices.create')
        ->and(acField($rows->firstWhere(fn ($a) => acField($a, 'permission_revoked')), 'permission_revoked'))
        ->toContain('invoices.view');
});

it('audits role deletion as a mass revoke', function () {
    $this->actingAs(acAdmin());
    $role = Role::create(['name' => 'doomed_role', 'guard_name' => 'web']);
    $holder = User::factory()->create();
    $holder->assignRole('doomed_role');

    acTenant();

    Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->callAction('delete');

    $entry = Activity::where('log_name', 'access_control')->latest('id')->first();

    expect(acField($entry, 'role_deleted'))->toContain('doomed_role')->toContain($holder->name);
});

/*
| ---- Seeding / CLI stays silent (no causer) ----------------------------- |
*/

it('writes no access-control rows during seeding / CLI (no authenticated causer)', function () {
    // beforeEach seeded the role+permission set with no auth.
    expect(Activity::where('log_name', 'access_control')->count())->toBe(0);

    User::factory()->create()->assignRole('viewer'); // direct grant, no auth → silent

    expect(Activity::where('log_name', 'access_control')->count())->toBe(0);
});
