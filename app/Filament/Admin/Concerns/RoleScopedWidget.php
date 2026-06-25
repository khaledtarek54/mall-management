<?php

namespace App\Filament\Admin\Concerns;

use App\Support\Modules;
use Illuminate\Support\Facades\Auth;

/**
 * Single source of truth for which dashboard widgets each Spatie role
 * sees on the admin dashboard.
 *
 * Why a trait + central map: Filament 4 evaluates `canView()` per widget,
 * so we'd duplicate role checks across 10+ classes. Instead each widget
 * uses this trait and only declares the role list it serves.
 */
trait RoleScopedWidget
{
    /**
     * Roles allowed to see this widget. super_admin always sees everything.
     * Override in the widget if needed.
     *
     * @return string[]
     */
    protected static function allowedRoles(): array
    {
        return ['super_admin', 'manager', 'viewer', 'leasing', 'operations'];
    }

    /**
     * Optional: feature-flag module this widget belongs to. If set and the
     * module is disabled in /admin/settings → Modules, the widget hides.
     * Return null for widgets that aren't tied to a toggleable module.
     */
    protected static function widgetModule(): ?string
    {
        return null;
    }

    public static function canView(): bool
    {
        $module = static::widgetModule();
        if ($module !== null && ! Modules::enabled($module)) {
            return false;
        }
        return static::roleAllowsView();
    }

    /**
     * Reusable role check. Extracted so widgets that need to compose
     * additional gates (e.g. a config flag + role) can call this directly.
     */
    protected static function roleAllowsView(): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->hasAnyRole(static::allowedRoles());
    }
}
