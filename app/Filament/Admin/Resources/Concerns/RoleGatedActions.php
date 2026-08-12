<?php

namespace App\Filament\Admin\Resources\Concerns;

use App\Support\DeletionPolicy;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Permission-based gating for Filament Resource CRUD actions.
 *
 * Each resource is associated with a permission module (auto-derived from
 * its model name; override `permissionModule()` to customize). Permissions
 * follow "{module}.{action}" naming and live in the Spatie permissions
 * table — see RolesPermissionsSeeder::PERMISSIONS for the full catalog.
 *
 *  - {module}.view   → canViewAny, canView
 *  - {module}.create → canCreate
 *  - {module}.edit   → canEdit, canRestore
 *
 * DELETE is special: project-wide, **only super_admin can delete** (single or
 * bulk, normal or force) regardless of any "{module}.delete" permission — a
 * destructive action is reserved for the platform owner. Bulk delete is
 * additionally off unless a resource opts in via $bulkDeletable.
 *
 * Custom roles with arbitrary permission combinations (e.g. "invoices.create
 * but not invoices.edit") work without any code change.
 */
trait RoleGatedActions
{
    /**
     * Whether **bulk** delete (and bulk force-delete) is allowed on this
     * resource. OFF by default across the whole project — a destructive
     * multi-row action shouldn't be one mis-click. Even when true, only
     * super_admin sees it (see canDeleteAny).
     *
     * Opt a resource back in by adding to it:
     *     protected static bool $bulkDeletable = true;
     */
    protected static bool $bulkDeletable = false;

    /**
     * Permission-key module for this resource. Defaults to the snake_case
     * plural of the model basename (Invoice → "invoices") which matches the
     * keys in RolesPermissionsSeeder::PERMISSIONS. Override in resources
     * whose model name doesn't match the module key (e.g. CamExpensePool
     * → "cam").
     */
    protected static function permissionModule(): string
    {
        $model = static::$model ?? null;
        if (! $model) {
            return '';
        }

        return Str::snake(Str::pluralStudly(class_basename($model)));
    }

    /**
     * The permission module this resource gates on, readable from outside.
     *
     * `hasPermission()` can only ever answer for the CURRENT session, because it reads
     * `Auth::user()`. A notification is built where there is no session — in a scheduled command or
     * a queued job — and still has to decide whether the operator it is addressed to may open the
     * link it is about to embed. Exposing the module key lets that question be asked of the
     * recipient directly (`$user->can("{$module}.view")`) instead of being answered "no" for
     * everyone by an absent session. See App\Support\NotificationLink::mayView().
     */
    public static function permissionModuleKey(): string
    {
        return static::permissionModule();
    }

    protected static function hasPermission(string $action): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        $module = static::permissionModule();
        if (! $module) {
            return false;
        }

        // Composite gate: module feature flag AND user permission.
        if (! Modules::enabled($module)) {
            return false;
        }

        return $user->can("{$module}.{$action}");
    }

    /** Delete is reserved for the platform owner, project-wide. */
    protected static function isSuperAdmin(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    /**
     * Hide the resource from the sidebar when its module is turned off.
     * Filament calls this once per render; cheap.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return Modules::enabled(static::permissionModule());
    }

    public static function canViewAny(): bool
    {
        return static::hasPermission('view');
    }

    public static function canView(Model $record): bool
    {
        return static::hasPermission('view');
    }

    public static function canCreate(): bool
    {
        return static::hasPermission('create');
    }

    public static function canEdit(Model $record): bool
    {
        return static::hasPermission('edit');
    }

    public static function canDelete(Model $record): bool
    {
        // Money and audit records are never deletable — not even by super_admin. A financial
        // document is a record of something that happened; it is corrected through its own
        // workflow (cancel / void / credit note), which leaves a trail an auditor can follow.
        // See App\Support\DeletionPolicy. Checked here, not per resource, so the answer is the
        // same everywhere it is asked.
        if (DeletionPolicy::isNeverDeletable(static::getModel())) {
            return false;
        }

        // Otherwise: only super_admin can delete — ignores the {module}.delete permission.
        return static::isSuperAdmin();
    }

    public static function canDeleteAny(): bool
    {
        if (DeletionPolicy::isNeverDeletable(static::getModel())) {
            return false;
        }

        // Bulk delete: opt-in per resource ($bulkDeletable) AND super_admin only.
        return static::$bulkDeletable && static::isSuperAdmin();
    }

    public static function canForceDelete(Model $record): bool
    {
        // Force delete destroys the row outright, so it is refused first and hardest.
        if (DeletionPolicy::isNeverDeletable(static::getModel())) {
            return false;
        }

        return static::isSuperAdmin();
    }

    public static function canForceDeleteAny(): bool
    {
        if (DeletionPolicy::isNeverDeletable(static::getModel())) {
            return false;
        }

        return static::$bulkDeletable && static::isSuperAdmin();
    }

    public static function canRestore(Model $record): bool
    {
        return static::hasPermission('edit');
    }

    public static function canRestoreAny(): bool
    {
        return static::hasPermission('edit');
    }
}
