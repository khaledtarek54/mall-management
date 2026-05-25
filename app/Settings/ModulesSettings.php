<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Feature flags for every optional module. Operators can turn modules
 * on/off from /admin/settings → Modules. Disabling a module:
 *  - removes its resource from the sidebar
 *  - blocks direct-URL access (canViewAny returns false)
 *  - hides its dashboard widgets
 *  - hides its dashboard action-required cards
 *
 * Modules NOT listed here are *core* — Properties, Units, Tenants, Leases,
 * Invoices, Payments, Users, Roles, Settings. The platform doesn't work
 * without them.
 *
 * Each property's default is set by the corresponding settings migration.
 */
class ModulesSettings extends Settings
{
    // All optional modules default to ON — matches the seed migration and
    // means a fresh clone with no settings DB rows still behaves correctly.
    public bool $credit_notes = true;
    public bool $maintenance = true;
    public bool $tenant_sales = true;
    public bool $cam = true;
    public bool $utility_meters = true;
    public bool $vendors = true;
    public bool $notes = true;
    public bool $reports = true;
    public bool $activity_log = true;

    public static function group(): string
    {
        return 'modules';
    }
}
