<?php

use App\Filament\Admin\Resources\Leases\Pages\ListLeases;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Filament\Admin\Resources\Units\Pages\ListUnits;
use App\Support\Imports;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * FR-USR-02 — "restrict data import/upload functionality to **Admin users only**; all other roles
 * may export/download but not import."
 *
 * THE GAP. Every ImportAction was gated on `canCreate()`, so every manager and the whole leasing
 * team could import. Import is not a flavour of create: creating a tenant is one considered row,
 * while one wrong CSV column rewrites hundreds at once and the damage surfaces later, in the
 * billing. The FRD singles it out for exactly that reason, and its role table makes the Admin "the
 * only role that can import/upload data".
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'IMP']);
});

dataset('import pages', [
    'tenants' => [ListTenants::class],
    'units' => [ListUnits::class],
    'leases' => [ListLeases::class],
]);

it('offers import to an admin', function (string $page) {
    $this->actingAs(makeUser('mall_admin', [$this->asset->id]));

    asTenant($this->asset, fn () => Livewire::test($page)->assertActionVisible('import'));
})->with('import pages');

it('offers import to the system owner', function (string $page) {
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    asTenant($this->asset, fn () => Livewire::test($page)->assertActionVisible('import'));
})->with('import pages');

it('hides import from a manager, who can still create', function (string $page) {
    // The regression in one assertion: a manager may create records, and could therefore import
    // hundreds. Those are different rights.
    $manager = makeUser('manager', [$this->asset->id]);
    $this->actingAs($manager);

    expect($manager->can('tenants.create'))->toBeTrue();  // still a manager
    expect($manager->can(Imports::PERMISSION))->toBeFalse(); // but not an importer

    asTenant($this->asset, fn () => Livewire::test($page)->assertActionHidden('import'));
})->with('import pages');

it('hides import from the roles that can see these records', function (string $page) {
    // leasing and viewer both hold `.view` on tenants/units/leases, so they reach the page and the
    // gate is what stops them. `operations` is deliberately absent: it has no `.view` on these
    // resources at all, so the page 403s and there is no component to inspect — it cannot import
    // because it cannot get there, which is a different guard and already covered by RBAC.
    foreach (['leasing', 'viewer'] as $role) {
        $this->actingAs(makeUser($role, [$this->asset->id]));
        asTenant($this->asset, fn () => Livewire::test($page)->assertActionHidden('import'));
    }
})->with('import pages');

/* ---- the rule itself ---------------------------------------------------- */

it('refuses an unauthenticated importer', function () {
    expect(Imports::allowed())->toBeFalse();
});

it('grants the import right to admins only', function () {
    // Pinned as a list so widening it is a deliberate edit with a test to change, not a side
    // effect of someone adding a permission that happens to match a filter.
    $allowed = collect(Role::all())
        ->filter(fn ($r) => $r->hasPermissionTo(Imports::PERMISSION))
        ->pluck('name')->sort()->values()->all();

    expect($allowed)->toBe(['mall_admin', 'super_admin']);
});

/* ---- the gate: a fourth import button cannot ship ungated ---------------- */

it('gates every ImportAction in the app on the import permission', function () {
    // Reflective, like PropertyIsolationConformanceTest: the three pages above are the ones that
    // exist TODAY. This fails the build when a new one appears gated on canCreate() — which is
    // precisely how the first three got it wrong.
    $offenders = [];

    foreach (rglob(app_path('Filament'), '*.php') as $file) {
        $source = file_get_contents($file);

        if (! str_contains($source, 'ImportAction::make')) {
            continue;
        }

        if (! str_contains($source, 'Imports::allowed()')) {
            $offenders[] = str_replace(base_path().'/', '', $file);
        }
    }

    expect($offenders)->toBe([], 'Every ImportAction must be gated on App\Support\Imports::allowed() (FR-USR-02)');
});

/** Recursively list files — the gate must see the whole tree, not one directory. */
function rglob(string $dir, string $pattern): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
        if ($file->isFile() && fnmatch($pattern, $file->getFilename())) {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}
