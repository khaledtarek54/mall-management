<?php

/**
 * The `{module}.delete` catalogue is gone, and deleting is decided the way it always was.
 *
 * `PermissionReach::NEVER_CHECKED` carried this for months as a standing decision nobody had taken:
 * *"either honour them or drop them — what should not continue is a permission that reads as a
 * right and grants nothing."* Forty-three keys, checked by no code, rendered as forty-three
 * checkboxes on the Roles screen, and four of them actually granted to `accounting`.
 *
 * **Honouring them was never available.** `RoleGatedActions::canDelete()` asks `DeletionPolicy` and
 * `canDeleteAny()` asks `isSuperAdmin()`. Neither consults a permission, because the operator
 * decided on 2026-07-31 that deletion is super-admin-only project-wide. Making `holidays.delete`
 * mean something would have reversed that decision, not implemented it.
 *
 * So what this pins is a NON-change in behaviour beside a real change in the catalogue: nobody
 * gained or lost the ability to delete anything.
 */

use App\Filament\Admin\Resources\Holidays\HolidayResource;
use App\Models\Holiday;
use App\Models\Invoice;
use App\Support\DeletionPolicy;
use App\Support\PermissionReach;
use Database\Seeders\RolesPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

it('seeds not one delete permission', function () {
    $seeded = Permission::query()
        ->where('name', 'like', '%.delete')
        ->pluck('name')
        ->all();

    expect($seeded)->toBe([]);

    // The control: the catalogue is not simply empty. 43 keys went; the rest must still be there.
    expect(Permission::query()->count())->toBeGreaterThan(100);
});

it('lists every retired key, and none of them is still in the catalogue', function () {
    $retired = DeletionPolicy::allRetiredPermissions();

    // Both waves: nine money records on 2026-07-31, forty-three on 2026-08-26.
    expect($retired)->toHaveCount(52)
        ->and(DeletionPolicy::retiredDeletePermissions())->toHaveCount(43);

    expect(Permission::query()->whereIn('name', $retired)->count())->toBe(0);

    // And the catalogue the seeder ships names none of them either — otherwise a re-seed would
    // quietly put them back the day after the migration removed them.
    $catalogue = collect(RolesPermissionsSeeder::PERMISSIONS)->flatMap(fn (array $g) => array_keys($g));

    expect($catalogue->intersect($retired)->all())->toBe([]);
});

it('changes nobody\'s ability to delete anything', function () {
    // The whole point. Deletion was decided by the ROLE and the policy before, and still is —
    // `canDelete()` resolves through `DeletionPolicy::resourceMayDelete()`, which asks
    // `hasRole('super_admin')` and never a permission.
    $holiday = Holiday::create([
        'date' => '2026-01-07',
        'kind' => 'closure',
        'name_en' => 'Christmas',
        'name_ar' => 'عيد الميلاد',
    ]);

    $this->actingAs(makeUser('super_admin'));
    expect(HolidayResource::canDelete($holiday))->toBeTrue();

    $this->flushSession();
    $this->actingAs(makeUser('manager'));
    expect(HolidayResource::canDelete($holiday))->toBeFalse();

    // `canDeleteAny()` is the BULK question and is off project-wide — unchanged in both directions.
    $this->flushSession();
    $this->actingAs(makeUser('super_admin'));
    expect(HolidayResource::canDeleteAny())->toBeFalse();
});

it('still refuses a record with history, even to a super admin', function () {
    // The other half of the policy, also untouched: `#[DeletableWhenUnused]` and
    // `#[NeverDeletable]` decide before the role does.
    $this->actingAs(makeUser('super_admin'));

    expect(DeletionPolicy::resourceMayDelete(Invoice::class))->toBeFalse();
});

it('leaves no role holding a permission that grants nothing', function () {
    // `accounting` genuinely held four of them — charge_codes, tax_codes, utility_tariffs and
    // account_mappings — which is exactly the confusion the standing note described: a right on
    // the roles matrix that did nothing at all.
    $holders = [];

    foreach (array_keys(RolesPermissionsSeeder::ROLES) as $name) {
        $held = Role::findByName($name, 'web')->permissions->pluck('name')
            ->filter(fn (string $p): bool => str_ends_with($p, '.delete'))
            ->all();

        $held === [] || $holders[$name] = $held;
    }

    expect($holders)->toBe([]);
});

it('keeps the never-checked category, empty, rather than deleting it', function () {
    // A permission the application can NEVER consult is a real class, distinct from one merely
    // unchecked today. Keeping the empty registry means the next one is recorded with its reason
    // instead of argued about from first principles again.
    expect(PermissionReach::NEVER_CHECKED)->toBe([]);
});
