<?php

use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * RolesPermissionsSeeder writes the catalogue with a bulk insert and wires every role's
 * grants in one pass, instead of `Permission::findOrCreate()` + `syncPermissions()` per
 * row (925ms a call → 11ms, paid once per test case in ~230 `beforeEach` blocks).
 *
 * Bulk writes bypass Eloquent events, which is exactly what made them fast and exactly
 * what could make them wrong: spatie invalidates its permission cache from a model
 * `saved` hook, so the seeder has to flush that cache itself. These tests pin the two
 * things the fast path could silently break — a stale cache, and a second run inserting
 * the catalogue twice.
 */
it('leaves no stale permission cache behind, so grants resolve immediately after seeding', function () {
    // Warm spatie's cache against an EMPTY catalogue first: if the seeder forgets to
    // flush after its bulk insert, every check below reads this stale, empty snapshot.
    app(PermissionRegistrar::class)->getPermissions();

    $this->seed(RolesPermissionsSeeder::class);

    $accounting = makeUser('accounting');

    expect($accounting->can('invoices.void'))->toBeTrue()
        ->and($accounting->can('payments.create'))->toBeTrue()
        ->and($accounting->can('leases.create'))->toBeFalse();
});

it('is idempotent — a second run neither duplicates the catalogue nor drops a grant', function () {
    $this->seed(RolesPermissionsSeeder::class);

    $before = Role::query()->with('permissions')->orderBy('name')->get()
        ->mapWithKeys(fn (Role $r) => [$r->name => $r->permissions->pluck('name')->sort()->values()->all()])
        ->all();

    $catalogueBefore = Permission::query()->count();

    $this->seed(RolesPermissionsSeeder::class);

    $after = Role::query()->with('permissions')->orderBy('name')->get()
        ->mapWithKeys(fn (Role $r) => [$r->name => $r->permissions->pluck('name')->sort()->values()->all()])
        ->all();

    expect($after)->toBe($before)
        ->and(Permission::query()->count())->toBe($catalogueBefore);

    $duplicates = DB::table('permissions')
        ->select('name', 'guard_name', DB::raw('COUNT(*) as tally'))
        ->groupBy('name', 'guard_name')
        ->havingRaw('COUNT(*) > 1')
        ->pluck('name')
        ->all();

    expect($duplicates)->toBe([], 'Duplicated permission rows: '.implode(', ', $duplicates));
});

it('creates every declared permission exactly once, on the web guard', function () {
    $this->seed(RolesPermissionsSeeder::class);

    $declared = collect(RolesPermissionsSeeder::PERMISSIONS)
        ->flatMap(fn (array $group) => array_keys($group))
        ->unique()
        ->sort()
        ->values()
        ->all();

    $stored = Permission::query()->orderBy('name')->pluck('name')->all();

    expect($stored)->toBe($declared)
        ->and(Permission::query()->where('guard_name', '!=', 'web')->count())->toBe(0);
});

it('grants super_admin the whole catalogue through the bulk pivot write', function () {
    $this->seed(RolesPermissionsSeeder::class);

    $catalogue = Permission::query()->pluck('name')->sort()->values()->all();
    $granted = Role::findByName('super_admin', 'web')
        ->permissions->pluck('name')->sort()->values()->all();

    expect($granted)->toBe($catalogue);
});

it('leaves roles it does not declare untouched', function () {
    $this->seed(RolesPermissionsSeeder::class);

    // A role an admin built in the UI: the bulk pivot rewrite clears grants for the
    // roles the seeder declares, and must not reach past them.
    $custom = Role::findOrCreate('night_shift_lead', 'web');
    $custom->syncPermissions(['invoices.view']);

    $this->seed(RolesPermissionsSeeder::class);

    expect($custom->fresh()->permissions->pluck('name')->all())->toBe(['invoices.view']);
});
