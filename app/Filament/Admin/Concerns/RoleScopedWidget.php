<?php

namespace App\Filament\Admin\Concerns;

use App\Support\DashboardLayout;
use App\Support\Modules;
use Illuminate\Support\Facades\Auth;

/**
 * Visibility gate for a dashboard widget: is it in this user's layout, and is its module on?
 *
 * The role decision itself lives in `App\Support\DashboardLayout` — this trait used to carry a
 * per-widget `allowedRoles()` list, thirteen of them, and no one could answer "what does a
 * marketing user see?" without opening all thirteen and intersecting them by hand. (Nothing. The
 * answer was nothing.) A widget now declares only the module it belongs to, if any.
 *
 * Belt-and-braces: `Dashboard` already composes from the same registry, so `canView()` is the
 * second lock rather than the only one — it still matters wherever Filament resolves a widget
 * outside the dashboard page.
 */
trait RoleScopedWidget
{
    /**
     * Optional: feature-flag module this widget belongs to. If set and the module is disabled in
     * /admin/settings → Modules, the widget hides regardless of role. Return null for widgets that
     * aren't tied to a toggleable module.
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
     * Reusable role check. Extracted so widgets that need to compose additional gates
     * (e.g. a config flag + role) can call this directly.
     */
    protected static function roleAllowsView(): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        return DashboardLayout::allows(static::class, $user);
    }
}
