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

    public bool $requests = true;

    public bool $tenant_sales = true;

    public bool $cam = true;

    public bool $utility_meters = true;

    public bool $vendors = true;

    public bool $notes = true;

    public bool $reports = true;

    public bool $activity_log = true;

    // ETA e-invoicing is FROZEN, not merely off. The property stays so `modules.eta` remains a
    // real key (a module outside Modules::KEYS is a guard that can never refuse), but nothing
    // reads it any more: `Modules::enabled('eta')` answers false from `Modules::FROZEN` before it
    // ever reaches this row, and the Settings screen renders no toggle for it. Unfreeze there.
    public bool $eta = false;

    public bool $inventory = true;

    public bool $fixed_assets = true;

    public bool $employees = true;

    public bool $custodies = true;

    public bool $facility = true;

    public bool $procurement = true;

    // The shopper-facing feed (module 36). ON by default like every other optional module — the
    // operator's marketing team can use the register (and the tenant-submission queue) before a
    // visitor app exists to render it.
    public bool $marketing_posts = true;

    public static function group(): string
    {
        return 'modules';
    }
}
