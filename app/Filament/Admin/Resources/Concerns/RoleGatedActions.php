<?php

namespace App\Filament\Admin\Resources\Concerns;

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
 *  - {module}.delete → canDelete, canForceDelete
 *
 * Custom roles with arbitrary permission combinations (e.g. "invoices.create
 * but not invoices.delete") work without any code change.
 */
trait RoleGatedActions
{
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
        return static::hasPermission('delete');
    }

    public static function canDeleteAny(): bool
    {
        return static::hasPermission('delete');
    }

    public static function canForceDelete(Model $record): bool
    {
        return static::hasPermission('delete');
    }

    public static function canForceDeleteAny(): bool
    {
        return static::hasPermission('delete');
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
