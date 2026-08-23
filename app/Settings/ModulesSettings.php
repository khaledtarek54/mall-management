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

    // Module 37 — buyers who own a unit outright and pay a صيانة assessment.
    public bool $unit_ownerships = true;

    // Parking bays, kiosks and storage let on their own agreements.
    public bool $rentable_items = true;

    // Published indices a lease escalation can be pegged to.
    public bool $rent_indices = true;

    // Module 33 — the lodged-cheque register.
    public bool $post_dated_cheques = true;

    // Security deposits held, applied and refunded.
    public bool $deposit_transactions = true;

    // EG-33 — the costs that arrive on a calendar rather than on an invoice.
    public bool $recurring_expenses = true;

    // Module 20 — owner statements, and the disbursements that settle them.
    public bool $owner_statements = true;

    // Module 15 — the request board an owner raises work on.
    public bool $owner_requests = true;

    // The bank register and the statement reconciliation that reads it.
    public bool $bank_accounts = true;

    // The annual budget screen and its variance figures.
    public bool $budget = true;

    // Module 31 — house-rule breaches and the fines billed from them.
    public bool $violations = true;

    // Module 30 — the zones a work order is routed by.
    public bool $areas = true;

    // The spend-approval ladder: the bands and the queue that walks them.
    public bool $approvals = true;

    // Module 24 — payroll runs and the dated statutory ladder they compute on.
    public bool $payrolls = true;

    // Module 13 — marketing budgets and the levy billed against them.
    public bool $marketing = true;

    // Mall news sent to tenants.
    public bool $announcements = true;

    // D-7 / EG-32 — operator-defined fields on the five extensible record types.
    public bool $custom_fields = true;

    // EG-15 — the standing wording on tenant-facing documents.
    public bool $document_templates = true;

    public static function group(): string
    {
        return 'modules';
    }
}
