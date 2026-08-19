<?php

use App\Filament\Admin\Pages\ActivityLog;
use App\Support\PermissionReach;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Every permission the catalogue grants is CHECKED somewhere, or says why it is not.
 *
 * **The class this closes.** The 2026-08-18 sweep named two bug types no gate caught. The first —
 * a service with no entry point — became `ServiceReachability`. The second is this one: a
 * permission granted to a role and consulted by nothing. It fails silently and in the confusing
 * direction: the role appears to hold the right, the screen refuses, and nobody can tell policy
 * from bug. `requests.change_status` was granted to `technician` while the action gated on
 * `canEdit()`, so that role's entire function was dead.
 *
 * **The reason this was hard, and why it is a registry rather than a grep.** Most keys are never
 * written down. `RoleGatedActions::hasPermission($action)` composes `"{$module}.{$action}"` at
 * runtime, so `invoices.edit` is checked on every page load and appears nowhere in the source. A
 * literal search reports about a hundred phantom misses, which is a gate nobody would keep.
 * `PermissionReach` derives the dynamic keys instead — and attributes them **by directory**, so an
 * action one module checks cannot vouch for another's.
 */
beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

it('leaves no permission unaccounted for', function () {
    $dynamic = PermissionReach::dynamicKeys();
    $assignmentScoped = PermissionReach::assignmentScopedModules();
    $sources = PermissionReach::phpUnder(app_path());

    $unreached = [];

    foreach (Permission::pluck('name') as $permission) {
        [$module, $action] = array_pad(explode('.', (string) $permission, 2), 2, '');

        $literal = false;

        foreach ($sources as $source) {
            if (str_contains($source, "'{$permission}'") || str_contains($source, "\"{$permission}\"")) {
                $literal = true;

                break;
            }
        }

        if ($literal
            || isset($dynamic[$module][$action])
            || ($action === 'view_all' && in_array($module, $assignmentScoped, true))
            || PermissionReach::neverCheckedReason((string) $permission) !== null
            || isset(PermissionReach::EXEMPT[$permission])) {
            continue;
        }

        $unreached[] = (string) $permission;
    }

    expect($unreached)->toBe([],
        "These permissions are granted and checked NOWHERE. A role holding one gets nothing, and the\n"
        ."screen that should honour it refuses without saying why. Check it, or classify it in\n"
        ."App\\Support\\PermissionReach::EXEMPT with a reason:\n  ".implode("\n  ", $unreached));
});

it('proves its own detection works — a permission nothing checks must be caught', function () {
    // The gate above passes trivially if the derivation credits everything. So invent a permission
    // that certainly nothing checks and require the same logic to flag it.
    Permission::findOrCreate('nonexistent_module.invented_action', 'web');

    $dynamic = PermissionReach::dynamicKeys();
    $sources = PermissionReach::phpUnder(app_path());

    $found = false;

    foreach ($sources as $source) {
        if (str_contains($source, "'nonexistent_module.invented_action'")) {
            $found = true;
        }
    }

    expect($found)->toBeFalse()
        ->and(isset($dynamic['nonexistent_module']['invented_action']))->toBeFalse()
        ->and(PermissionReach::neverCheckedReason('nonexistent_module.invented_action'))->toBeNull();
});

it('attributes a dynamic action to its OWN module and no other', function () {
    // The loosening that would make this gate meaningless: crediting every module with every action
    // any resource calls. `announcements.send` and `violations.notify` are both `hasPermission()`
    // calls in different directories — neither may vouch for the other.
    $dynamic = PermissionReach::dynamicKeys();

    expect($dynamic['announcements']['send'] ?? false)->toBeTrue()
        ->and($dynamic['violations']['send'] ?? false)->toBeFalse('an action leaked across modules')
        ->and($dynamic['violations']['notify'] ?? false)->toBeTrue()
        ->and($dynamic['announcements']['notify'] ?? false)->toBeFalse('an action leaked across modules');
});

it('reads the module from the right argument of AssignmentScope', function () {
    // `apply($query, 'facility', $column)` — the module is the SECOND argument. Reading it as the
    // first reports every assignment-scoped module as unreached, which is a gate that fails on
    // things that are fine, and those get switched off rather than fixed.
    expect(PermissionReach::assignmentScopedModules())
        ->toContain('facility')
        ->toContain('requests');
});

it('makes every classification carry a reason someone can review', function () {
    foreach (PermissionReach::NEVER_CHECKED as $key => $reason) {
        expect(mb_strlen($reason))->toBeGreaterThan(60, "[{$key}] is excused with a reason too thin to review");
    }

    foreach (PermissionReach::EXEMPT as $key => $reason) {
        expect(mb_strlen($reason))->toBeGreaterThan(60, "[{$key}] is excused with a reason too thin to review");
        expect(Permission::where('name', $key)->exists())
            ->toBeTrue("[{$key}] is exempted but no longer exists — a stale exemption reads as a considered decision");
    }
});

it('gates the activity log on its permission, and withholds that permission from mall_admin', function () {
    // The one live drift this registry found. The page named `['super_admin','manager','viewer']`
    // inline while `activity_log.view` was checked nowhere — and `mall_admin` inherits every manager
    // permission, so it HELD the right and was refused by the screen.
    //
    // The feed spans every property and carries no `asset_id`, so a property-restricted admin must
    // not have it. Both halves are asserted because fixing one alone recreates the disagreement in
    // the other direction.
    $holders = Role::with('permissions')->get()
        ->filter(fn ($role) => $role->permissions->pluck('name')->contains('activity_log.view'))
        ->pluck('name')
        ->sort()
        ->values()
        ->all();

    expect($holders)->toBe(['manager', 'super_admin', 'viewer'],
        'activity_log.view is held by a property-restricted role — the feed cannot be scoped');

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->actingAs(makeUser('manager'));
    expect(ActivityLog::canAccess())->toBeTrue('a manager can no longer open the activity log');

    $this->actingAs(makeUser('mall_admin'));
    expect(ActivityLog::canAccess())->toBeFalse('a property-restricted admin reached the portfolio-wide feed');
});
