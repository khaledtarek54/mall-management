<?php

use App\Models\User;
use App\Support\AccessControlAudit;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Access-control audit trail: role grants/revokes (via spatie events) and
 * role→permission edits (via the Role pages' explicit diff) are written to the
 * activity log under the 'access_control' log name — but only for authenticated,
 * human-initiated changes (seeding / CLI grants are intentionally not logged).
 */
function latestAccessControl(): ?Activity
{
    return Activity::where('log_name', 'access_control')->latest('id')->first();
}

it('logs an access-control entry when an authenticated admin grants a role', function () {
    $admin = makeUser('super_admin');   // before actingAs -> no causer -> not logged
    $this->actingAs($admin);

    $target = User::factory()->create();
    $target->assignRole('leasing');  // fires RoleAttachedEvent

    $entry = latestAccessControl();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($admin->id)
        ->and($entry->subject_id)->toBe($target->id)
        ->and(data_get($entry->properties, 'attributes.role_granted'))->toContain('leasing');
});

it('logs an access-control entry when a role is revoked', function () {
    $admin = makeUser('super_admin');
    $target = makeUser('viewer');        // grant happens before actingAs -> not logged
    $this->actingAs($admin);

    $target->removeRole('viewer');       // fires RoleDetachedEvent

    $entry = latestAccessControl();

    expect($entry->subject_id)->toBe($target->id)
        ->and(data_get($entry->properties, 'attributes.role_revoked'))->toContain('viewer');
});

it('does NOT log access-control changes without an authenticated causer (seeding / CLI)', function () {
    makeUser('viewer'); // syncRoles with no auth context — the seeding/CLI case

    expect(Activity::where('log_name', 'access_control')->count())->toBe(0);
});

it('records permission grants against a role subject', function () {
    $admin = makeUser('super_admin');
    $this->actingAs($admin);
    $role = Role::findByName('viewer', 'web');

    AccessControlAudit::log($role, 'permission_granted', ['invoice.view']);

    $entry = latestAccessControl();

    expect($entry->subject_id)->toBe($role->id)
        ->and(class_basename($entry->subject_type))->toBe('Role')
        ->and(data_get($entry->properties, 'attributes.permission_granted'))->toContain('invoice.view');
});

it('normalises every spatie event payload shape (ids, model, collection) to names', function () {
    makeUser('super_admin'); // seeds the role set
    $role = Role::findByName('leasing', 'web');
    $perm = Permission::findOrCreate('test.view', 'web');

    // role attach/detach + permission attach carry an array of primary keys
    expect(AccessControlAudit::namesFrom([$role->id], Role::class))->toBe(['leasing'])
        // permission detach carries an Eloquent model …
        ->and(AccessControlAudit::namesFrom($perm, Permission::class))->toBe([$perm->name])
        // … or a collection of them
        ->and(AccessControlAudit::namesFrom(collect([$perm]), Permission::class))->toBe([$perm->name]);
});
