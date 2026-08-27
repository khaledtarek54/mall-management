<?php

namespace App\Support;

use Filament\Facades\Filament;
use ReflectionClass;

/**
 * Every permission in the catalogue is CHECKED somewhere — or says why it is not.
 *
 * **The gap this closes.** A UI/UX sweep on 2026-08-18 found two bug classes no gate caught: a
 * service with no entry point, and **a permission granted to a role but checked nowhere**. The first
 * became `ServiceReachability`. The second stayed a note — and it is the one with the sharper edge,
 * because it fails *silently in the operator's favour's opposite direction*: the role appears to
 * have the right, the screen refuses, and nobody can tell whether that is policy or a bug.
 *
 * It has bitten. `requests.change_status` was granted to `technician` — a role deliberately withheld
 * `requests.edit` — while the action gated on `canEdit()`, so the entire function of that role was
 * dead. Fixed; ungated until now, so the class could recur on the next module.
 *
 * ## Why a naive grep reports ~100 false misses
 *
 * Most permission keys are never written as literals. `RoleGatedActions::hasPermission($action)`
 * builds `"{$module}.{$action}"` at runtime from the resource's own module key, so
 * `$user->can('invoices.edit')` appears nowhere in the codebase and is checked on every page load.
 * `AssignmentScope` does the same for `{module}.view_all`.
 *
 * So reachability is DERIVED, three ways, in this order:
 *
 *  1. **A literal** — the string appears in `app/`.
 *  2. **A dynamic key attributed to its own module** — `hasPermission('foo')` inside the files of
 *     the resource whose `permissionModuleKey()` is `{module}` reaches `{module}.foo`. Attribution
 *     is by DIRECTORY, deliberately: crediting every module with every action anyone calls would
 *     let `violations.notify` be "reached" because the announcements resource calls
 *     `hasPermission('send')`, which is the loosening that makes a gate stop meaning anything.
 *  3. **`{module}.view_all`** — built by `AssignmentScope::apply($query, '{module}', …)`.
 *
 * Anything left must be classified here.
 */
class PermissionReach
{
    /**
     * The CRUD actions `RoleGatedActions` builds for every resource that uses it, without the
     * resource writing anything.
     *
     * `delete` is NOT here, and that is the point of {@see NEVER_CHECKED}.
     *
     * @var array<int, string>
     */
    public const TRAIT_ACTIONS = ['view', 'create', 'edit'];

    /**
     * Permissions the application can never consult, with the reason — a stated non-check.
     *
     * **Empty since 2026-08-26, and that is the point.** It held the whole `{module}.delete`
     * family for months under a standing note — *either honour them or drop them; what should not
     * continue is a permission that reads as a right and grants nothing* — and they were dropped.
     * `RoleGatedActions::canDelete()` never consulted them: deletion is super-admin-only
     * project-wide ({@see DeletionPolicy}), so making them mean something would have reversed the
     * operator's decision rather than implemented it.
     *
     * The category stays because it is a real one. A permission the application can NEVER consult
     * is different from one that is merely unchecked today ({@see EXEMPT}), and the next
     * such key should be recorded here with its reason rather than argued about from first
     * principles again.
     *
     * @var array<string, string> permission (or `*.action` wildcard) => why it is never checked
     */
    public const NEVER_CHECKED = [
        // RESOLVED 2026-08-26. The `{module}.delete` family is gone: the nine on money records went
        // on 2026-07-31 and the remaining forty-three were retired by
        // `2026_08_26_900000_retire_the_rest_of_the_delete_permissions`. See
        // `DeletionPolicy::retiredDeletePermissions()` for why dropping was the only consistent
        // direction — honouring them would have reversed the operator's decision that deletion is
        // super-admin-only, not implemented it. Left EMPTY rather than deleted: a permission the
        // application can never consult is a real category, and the next one should be recorded
        // here with its reason rather than argued about from scratch.
    ];

    /**
     * Permissions that are genuinely unchecked today, each with what that means and what would fix it.
     *
     * A reason is mandatory and "will be used later" is not one — that is exactly what the vendor
     * scorecard's backlog entry said for a month while the work was already done
     * ({@see ServiceReachability}).
     *
     * @var array<string, string>
     */
    public const EXEMPT = [
        'notes.view' => 'Notes are a polymorphic relation manager mounted on a parent record, so '.
            'seeing them is governed by whether you can view the PARENT — there is no separate notes '.
            'screen to gate. `notes.create` and `notes.edit` are checked as literals on the relation '.
            'manager\'s actions; only the read is inherited. Honouring this key would mean hiding the '.
            'relation manager, which is a real option and not today\'s behaviour.',

        'owner_statements.view_own' => 'Reserved for an owner-facing surface in `/admin` that does '.
            'not exist yet. The `/owner` panel that used to gate on it was RETIRED (module 15), and '.
            '[module 32 §9](docs/modules/32-owner-statements.md) records the decision that owner '.
            'surfaces are rebuilt in `/admin` gated on this key. Until one is built the permission is '.
            'inert — which is why it is named here rather than left to be rediscovered.',
    ];

    /**
     * Permissions reached dynamically, keyed `module => [action => true]`.
     *
     * @return array<string, array<string, bool>>
     */
    public static function dynamicKeys(): array
    {
        $dynamic = [];

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            if (! method_exists($resource, 'permissionModuleKey')) {
                continue;
            }

            $module = $resource::permissionModuleKey();

            if ($module === '') {
                continue;
            }

            foreach (self::TRAIT_ACTIONS as $action) {
                $dynamic[$module][$action] = true;
            }

            $dir = dirname((new ReflectionClass($resource))->getFileName());

            foreach (self::phpUnder($dir) as $source) {
                preg_match_all("/hasPermission\(\s*'([a-z_]+)'/", $source, $matches);

                foreach ($matches[1] ?? [] as $action) {
                    $dynamic[$module][$action] = true;
                }
            }
        }

        return $dynamic;
    }

    /**
     * Modules whose `view_all` key `AssignmentScope` builds.
     *
     * Every quoted literal in the call is collected rather than the second argument specifically,
     * and that is a deliberate retreat from precision. The first attempt matched the argument by
     * POSITION — and the real call passes `static::scopeToProperty(parent::getEloquentQuery())`
     * first, whose own parentheses end the match before it reaches the module. `requests` read as
     * unreached, which is a gate failing on something that is fine, and those get switched off
     * rather than fixed.
     *
     * The cost is that the column name (`'assigned_to'`) joins the set too. It is harmless: nothing
     * grants `assigned_to.view_all`, so an extra key here can only ever match a permission that does
     * not exist. Widening what counts as reached is the risk worth watching, and it is bounded to
     * the literals inside two call sites — both named in `PermissionReachConformanceTest`.
     *
     * @return array<int, string>
     */
    public static function assignmentScopedModules(): array
    {
        $modules = [];

        foreach (self::phpUnder(app_path()) as $source) {
            if (! preg_match_all('/AssignmentScope::\w+\((.{0,300})/s', $source, $calls)) {
                continue;
            }

            foreach ($calls[1] as $arguments) {
                preg_match_all("/'([a-z_]+)'/", $arguments, $literals);

                foreach ($literals[1] ?? [] as $literal) {
                    $modules[$literal] = true;
                }
            }
        }

        return array_keys($modules);
    }

    /** Is this permission covered by a `NEVER_CHECKED` entry, directly or by `*.action` wildcard? */
    public static function neverCheckedReason(string $permission): ?string
    {
        if (isset(self::NEVER_CHECKED[$permission])) {
            return self::NEVER_CHECKED[$permission];
        }

        $action = str_contains($permission, '.') ? substr($permission, strrpos($permission, '.') + 1) : $permission;

        return self::NEVER_CHECKED['*.'.$action] ?? null;
    }

    /**
     * Every PHP file under a directory, as path => contents.
     *
     * @return array<string, string>
     */
    public static function phpUnder(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[str_replace(base_path().'/', '', $file->getPathname())] = file_get_contents($file->getPathname());
            }
        }

        return $files;
    }
}
