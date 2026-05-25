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
    public bool $credit_notes;
    public bool $maintenance;
    public bool $tenant_sales;
    public bool $cam;
    public bool $utility_meters;
    public bool $vendors;
    public bool $notes;
    public bool $reports;
    public bool $activity_log;

    public static function group(): string
    {
        return 'modules';
    }
}
