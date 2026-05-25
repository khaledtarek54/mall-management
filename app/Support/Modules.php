<?php

namespace App\Support;

use App\Settings\ModulesSettings;

/**
 * Static helper around ModulesSettings — used everywhere a module needs
 * to ask "am I turned on?". Cached per-request via Laravel's container
 * (settings are loaded once when first requested).
 *
 *   if (! Modules::enabled('credit_notes')) {
 *       return false; // hide from nav, block route access, etc.
 *   }
 *
 * `KEYS` is the canonical list of toggleable module names. Anything not
 * in this list is treated as *core* and `enabled()` always returns true
 * for it.
 */
class Modules
{
    /** @var string[] */
    public const KEYS = [
        'credit_notes',
        'maintenance',
        'tenant_sales',
        'cam',
        'utility_meters',
        'vendors',
        'notes',
        'reports',
        'activity_log',
    ];

    public static function enabled(string $module): bool
    {
        if (! in_array($module, self::KEYS, true)) {
            // Core modules — always on.
            return true;
        }

        return (bool) (app(ModulesSettings::class)->{$module} ?? true);
    }
}
