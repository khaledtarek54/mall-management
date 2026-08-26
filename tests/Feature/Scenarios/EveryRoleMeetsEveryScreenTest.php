<?php

/**
 * All 14 roles against all 99 admin screens.
 *
 * `AuthorizationMatrixTest` asks `canView()` / `canCreate()` of a hand-picked set of resources for
 * eight of the fourteen roles. That is the right test for the RULES, and it is a different claim
 * from this one. Two things it cannot see:
 *
 *  1. **The five FRD roles it never names** — `technician`, `coordinator`, `customer_service`,
 *     `vendor`, `mall_admin`. Measured: those five appear ZERO times in it, and they are the roles
 *     with the most specific written boundaries and the least coverage.
 *  2. **A screen that CRASHES for a role rather than refusing it.** `canAccess()` answering true
 *     says nothing about whether the page RENDERS: a widget, a badge, a column closure or a filter
 *     can reach for something a narrow role does not hold, and the operator meets a 500. Only a
 *     real request through the panel's middleware tells 200 from 403 from 500 — and the difference
 *     between the last two is the difference between a policy and a bug.
 *
 * The HTTP half lives in RoleScreenMatrixShard{1..N}Test.php off Tests\Support\RoleMatrix: it is
 * 1,386 real requests and took 255s as one case, which Pest — parallelising per FILE — would have
 * turned into the floor under the whole suite. The first test below is what stops the split from
 * losing a role.
 *
 * What stays here is the two questions that are about the matrix as a WHOLE rather than about one
 * role, and that cost no HTTP at all.
 */

use App\Support\Navigation;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Tests\Support\RoleMatrix;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->asset = makeAsset(['code' => 'RM']);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('sweeps every role exactly once across the shards', function () {
    // The shards ARE the matrix, so this is what guarantees it still walks every role: a role that
    // falls out of the partition is a role nobody tests, and nothing else would notice. Cheap — no
    // seeding, no requests.
    $all = RoleMatrix::roles();

    $covered = [];
    for ($shard = 1; $shard <= RoleMatrix::SHARDS; $shard++) {
        $covered = [...$covered, ...RoleMatrix::rolesForShard($shard)];
    }
    sort($covered);

    // Compared against the SEEDER's own list, never against `BY_BREADTH`: a gate that reads only
    // the registry it guards cannot see what that registry omits, so a role added to the seeder and
    // forgotten in the balancing order has to fail here.
    expect($covered)->toBe($all, 'The shards do not partition the roles.');
    expect($all)->toHaveCount(count(array_unique($all)), 'A role is swept twice.');
    expect(count($all))->toBe(14, 'The role catalogue changed — update the matrix and its docs.');

    expect(glob(base_path('tests/Feature/Scenarios/RoleScreenMatrixShard*Test.php')))
        ->toHaveCount(RoleMatrix::SHARDS, 'RoleMatrix::SHARDS and the shard files on disk disagree.');
});

it('leaves no screen that every role is refused', function () {
    // The failure CLAUDE.md records for the trade and failure-code registers: a screen ships whose
    // permission was never seeded, and it is absent from the navigation for EVERYONE — super_admin
    // included — with no error and no clue, because `canAccess()` just returns false. A green suite
    // does not notice, since tests seed the catalogue themselves.
    $unreachable = [];

    foreach (Navigation::placed() as $screen) {
        $anyone = false;

        foreach (RoleMatrix::roles() as $role) {
            RoleMatrix::actAs($this, $role, $this->asset);

            if (asTenant($this->asset, fn () => $screen::canAccess())) {
                $anyone = true;

                break;
            }
        }

        $anyone || $unreachable[] = $screen;
    }

    expect($unreachable)->toBe([], "Screens no role can reach:\n".implode("\n", $unreachable));
});

it('never shows a role a sidebar link it would be refused', function () {
    // `visible()` is not authorization, and its converse is a usability defect with the same root:
    // an item in the sidebar that 403s on click. CLAUDE.md records that shape from module 08 ("an
    // enabled button that 403'd mid-workflow"); this asserts the sidebar itself cannot do it.
    //
    // Only one direction is asserted. A screen that is accessible and ABSENT from the sidebar is
    // legitimate — `Navigation::isVisibleTo()` also refuses clustered and parented screens, which
    // are reached from their parent rather than from the sidebar.
    $mismatched = [];
    $offered = 0;

    foreach (RoleMatrix::roles() as $role) {
        RoleMatrix::actAs($this, $role, $this->asset);

        asTenant($this->asset, function () use ($role, &$mismatched, &$offered) {
            foreach (Navigation::placed() as $screen) {
                if (! Navigation::isVisibleTo($screen)) {
                    continue;
                }

                $offered++;

                $screen::canAccess() || $mismatched[] = $role.' → '.class_basename($screen)
                    .' is in the sidebar and refused on click';
            }
        });
    }

    expect($mismatched)->toBe([], implode("\n", $mismatched));

    // The control: a sidebar that offered nothing to anybody would satisfy the sweep above.
    expect($offered)->toBeGreaterThan(100, 'The sidebar offered almost nothing — the sweep proves no rule.');
});

/**
 * Screens every authenticated user may open, each with the reason.
 *
 * The list is short on purpose and every entry is a screen that shows the reader THEIR OWN things
 * or nothing at all. Anything that renders records belongs behind a permission.
 */
const UNIVERSAL_SCREENS = [
    'Dashboard' => 'The landing page. Its widgets are individually gated by DashboardLayout, so what a role sees there is already decided per widget.',
    'Handbook' => 'The documentation panel — it renders the built handbook site, not a single record.',
    'NotificationCenter' => 'The reader\'s OWN notification bell. It is scoped to the authenticated user by definition.',
];

it('has no screen that every single role can open', function () {
    // A Filament page with no `canAccess()` is reachable by every authenticated panel user, and a
    // missing method looks like nothing at all in review. `OccupancyMap` was one: it renders each
    // unit with the NAME OF THE TENANT trading in it, and an external maintenance contractor could
    // read the whole mall's occupancy from it (see
    // AnExternalVendorCannotReadTheOccupancyMapTest). Its two neighbours in the same navigation
    // group both gated on `reports.view`.
    //
    // Reflection is the wrong probe here — a Resource inherits `canAccess()` from Filament and
    // gates in `canViewAny()` instead, so reading the declaring class reports all 66 of them as
    // ungated. What matters is the BEHAVIOUR: a screen no role is refused is a screen with no lock,
    // however it is written.
    $reachableByAll = [];

    foreach (Navigation::placed() as $screen) {
        $refusedBySomebody = false;

        foreach (RoleMatrix::roles() as $role) {
            RoleMatrix::actAs($this, $role, $this->asset);

            if (! asTenant($this->asset, fn () => $screen::canAccess())) {
                $refusedBySomebody = true;

                break;
            }
        }

        $refusedBySomebody || $reachableByAll[] = class_basename($screen);
    }

    $unexplained = array_values(array_diff($reachableByAll, array_keys(UNIVERSAL_SCREENS)));

    expect($unexplained)->toBe([], "Screens every role can open, with no reason recorded:\n"
        .implode("\n", $unexplained)
        ."\n\nEither gate the screen, or add it to UNIVERSAL_SCREENS with the reason it shows nobody another party's data.");

    // A stale exemption is the other half. A screen listed as universal that has since been gated
    // should lose its entry, or the list stops describing the panel.
    expect(array_values(array_diff(array_keys(UNIVERSAL_SCREENS), $reachableByAll)))
        ->toBe([], 'UNIVERSAL_SCREENS names a screen that is no longer reachable by every role.');
});

it('never lets a role write to a resource it cannot read', function () {
    // Screen access is one question; what you may DO there is another, and a gate can be wrong in
    // the direction no screen test sees. `canCreate()` and `canEdit()` are separate methods with
    // separate permission keys, so a resource can refuse the list and still accept a create — and
    // `CreateRecord`/`EditRecord` are their OWN routes: not reaching the list does not mean not
    // reaching the form.
    //
    // All 66 resources × all 14 roles, statically — no HTTP, so it costs nothing.
    $violations = [];

    foreach (RoleMatrix::roles() as $role) {
        RoleMatrix::actAs($this, $role, $this->asset);

        asTenant($this->asset, function () use ($role, &$violations) {
            foreach (Navigation::placed() as $screen) {
                if (! is_subclass_of($screen, Resource::class) || $screen::canViewAny()) {
                    continue;
                }

                $screen::canCreate() && $violations[] = $role.' → '.class_basename($screen).' may CREATE but not view';
            }
        });
    }

    expect($violations)->toBe([], implode("\n", $violations));
});

it('gives delete to nobody but super_admin, on every resource', function () {
    // The project-wide rule (`DeletionPolicy`, operator decision 2026-07-31): deletion is
    // super_admin-only, and money records are refused even to them. `AuthorizationMatrixTest`
    // asserts it on "a representative record"; this asks all 66.
    $canDelete = [];
    $superAdminCanDeleteSomething = false;

    foreach (RoleMatrix::roles() as $role) {
        RoleMatrix::actAs($this, $role, $this->asset);

        asTenant($this->asset, function () use ($role, &$canDelete, &$superAdminCanDeleteSomething) {
            foreach (Navigation::placed() as $screen) {
                if (! is_subclass_of($screen, Resource::class)) {
                    continue;
                }

                if (! $screen::canDeleteAny()) {
                    continue;
                }

                $role === 'super_admin'
                    ? $superAdminCanDeleteSomething = true
                    : $canDelete[] = $role.' → '.class_basename($screen);
            }
        });
    }

    expect($canDelete)->toBe([], "Non-super_admin roles holding delete:\n".implode("\n", $canDelete));

    // The control: `canDeleteAny()` is not simply false everywhere, which would satisfy the sweep
    // above while proving nothing about the rule.
    expect($superAdminCanDeleteSomething)->toBeTrue('No resource is deletable by anyone — the sweep proves no rule.');
});

it('lets the read-only roles write nothing at all', function () {
    // `viewer` is "read-only access for stakeholders + auditors" and `owner` is Jawad's read-only
    // oversight. Both hold `.view` across most of the catalogue, which is exactly the shape where a
    // stray create/edit grant would be least visible.
    // The one documented exception, named with its reason rather than waved through by loosening
    // the rule: `RolesPermissionsSeeder::ROLES` defines owner as "read-only oversight of owned
    // properties in the admin app AND OWNER REQUESTS" — raising a request to the operator is the
    // whole point of the owner having a login.
    $allowed = ['owner → OwnerRequestResource (create)'];

    $writes = [];

    foreach (['viewer', 'owner'] as $role) {
        RoleMatrix::actAs($this, $role, $this->asset);

        asTenant($this->asset, function () use ($role, &$writes) {
            foreach (Navigation::placed() as $screen) {
                if (! is_subclass_of($screen, Resource::class)) {
                    continue;
                }

                $screen::canCreate() && $writes[] = $role.' → '.class_basename($screen).' (create)';
            }
        });
    }

    expect(array_values(array_diff($writes, $allowed)))
        ->toBe([], "A read-only role holds write authority:\n".implode("\n", array_diff($writes, $allowed)));

    // And the exception must still be REAL — a stale entry here would quietly excuse a grant that
    // has since moved somewhere else.
    expect(array_values(array_diff($allowed, $writes)))
        ->toBe([], 'A documented write exception no longer exists — remove it from $allowed.');

    // The control: a role that SHOULD write can, so "nothing can be created" is not the reason.
    RoleMatrix::actAs($this, 'manager', $this->asset);

    $managerCreates = asTenant($this->asset, fn () => count(array_filter(
        Navigation::placed(),
        fn (string $screen): bool => is_subclass_of($screen, Resource::class) && $screen::canCreate(),
    )));

    expect($managerCreates)->toBeGreaterThan(20, 'The manager can create nothing — the refusals above prove no rule.');
});

it('gives every role at least one screen, and only super_admin all of them', function () {
    // A role that reaches nothing is a login nobody can use; a role that reaches everything is a
    // super admin under another name. Both are policy failures rather than crashes, so neither
    // shows up in the shards' status codes.
    $breadth = [];

    foreach (RoleMatrix::roles() as $role) {
        RoleMatrix::actAs($this, $role, $this->asset);

        $breadth[$role] = asTenant($this->asset, fn () => count(array_filter(
            Navigation::placed(),
            fn (string $screen): bool => $screen::canAccess(),
        )));
    }

    $total = count(Navigation::placed());

    expect($breadth['super_admin'])->toBe($total);

    foreach ($breadth as $role => $count) {
        expect($count)->toBeGreaterThan(0, "The {$role} role can reach no screen at all.");

        $role === 'super_admin' || expect($count)->toBeLessThan(
            $total,
            "The {$role} role reaches all {$total} screens — it is a super admin in all but name.",
        );
    }
});
