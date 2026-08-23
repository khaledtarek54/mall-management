<?php

namespace App\Support;

use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
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
    /**
     * Every module an operator may switch on or off, grouped as the Settings screen renders them.
     *
     * The grouping is not decoration. This started as sixteen keys in one flat list and a single
     * two-column wall of switches; at thirty-four that is unreadable, and an operator hunting for
     * "can I turn owner statements off" scans a list with no order to it. The sections are the same
     * areas the sidebar uses, so the question "where do I switch this off" has the same answer as
     * "where do I find it".
     *
     * A module key is also the PERMISSION module of the resources it governs, which is what makes
     * the gate free: {@see RoleGatedActions} already asks
     * `Modules::enabled(permissionModule())` on every `canViewAny()` and on navigation, so adding a
     * key here is the whole of switching a module off — no per-resource edit, no new call site.
     * Catalogue screens that belong TO a module are mapped in {@see FEATURE_OF} instead of getting
     * a switch of their own.
     *
     * @var array<string, string[]> section => module keys
     */
    public const GROUPS = [
        'leasing' => [
            'unit_ownerships',
            'rentable_items',
            'rent_indices',
        ],
        'receivables' => [
            'credit_notes',
            'post_dated_cheques',
            'deposit_transactions',
        ],
        'recoveries' => [
            'cam',
            'utility_meters',
            'tenant_sales',
        ],
        'payables' => [
            'vendors',
            'procurement',
            'recurring_expenses',
        ],
        'owners' => [
            'owner_statements',
            'owner_requests',
        ],
        'general_ledger' => [
            'bank_accounts',
            'budget',
        ],
        'operations' => [
            'requests',
            'violations',
            'areas',
            'approvals',
            'notes',
        ],
        'facility' => [
            'facility',
        ],
        'inventory_assets' => [
            'inventory',
            'fixed_assets',
        ],
        'hr_payroll' => [
            'employees',
            'payrolls',
            'custodies',
        ],
        'marketing' => [
            'marketing',
            'announcements',
            // The shopper-facing feed (module 36). Toggleable because it is the one module whose
            // value depends on something outside this system existing — a mall with no visitor app
            // has nothing to publish to, and should not be asked to review offers nobody will read.
            // Turning it off also 404s the public API (see the route group), not just the nav item.
            'marketing_posts',
        ],
        'administration' => [
            'reports',
            'activity_log',
            'custom_fields',
            'document_templates',
            // Frozen in code (see FROZEN) — listed so the key keeps a home, never rendered.
            'eta',
        ],
    ];

    /**
     * The canonical list of toggleable module names, derived from {@see GROUPS}.
     *
     * Derived rather than restated: a key in one and not the other is a switch that governs nothing
     * or a module with no switch, and both failures are silent — `enabled()` answers TRUE for
     * anything outside this list, so an unlisted key is a guard that can never refuse.
     *
     * @var string[]
     */
    public const KEYS = [
        // Leasing
        'unit_ownerships',
        'rentable_items',
        'rent_indices',
        // Receivables
        'credit_notes',
        'post_dated_cheques',
        'deposit_transactions',
        // Recoveries
        'cam',
        'utility_meters',
        'tenant_sales',
        // Payables
        'vendors',
        'procurement',
        'recurring_expenses',
        // Owners
        'owner_statements',
        'owner_requests',
        // General ledger
        'bank_accounts',
        'budget',
        // Operations
        'requests',
        'violations',
        'areas',
        'approvals',
        'notes',
        // Facility
        'facility',
        // Inventory & assets
        'inventory',
        'fixed_assets',
        // HR & payroll
        'employees',
        'payrolls',
        'custodies',
        // Marketing
        'marketing',
        'announcements',
        'marketing_posts',
        // Administration
        'reports',
        'activity_log',
        'custom_fields',
        'document_templates',
        'eta',
    ];

    /**
     * A screen's own module key => the module that actually decides whether it is on.
     *
     * Catalogues belong to the module they configure. "Failure codes" is not a feature somebody
     * turns off; it is part of Facility, and giving it a switch of its own would mean an operator
     * could turn Facility off and still be offered the code list that only Facility reads — or
     * worse, leave Facility on and silently remove the vocabulary its work orders classify by.
     *
     * Resolved in {@see enabled()} BEFORE the {@see KEYS} check, so `Modules::enabled('trades')`
     * answers whatever `facility` answers. That replaces the hand-written
     * `Modules::enabled('facility') && parent::canAccess()` that three facility catalogues carried
     * and the other six did not — the same rule in nine places, honoured in three, which is how
     * `utility_tariffs` stayed in the sidebar with Utility meters switched off.
     *
     * @var array<string, string> follower => owning module key
     */
    public const FEATURE_OF = [
        // Facility (module 26) — the vocabulary its work orders and plans are classified by.
        'trades' => 'facility',
        'failure_codes' => 'facility',
        'work_permits' => 'facility',
        // Tenant requests (module 11) — what a tenant may report is that module's own taxonomy.
        'tenant_request_subcategories' => 'requests',
        // Violations (module 31) — the schedule of penalties.
        'violation_categories' => 'violations',
        // Vendors (module 12) — which certificates block dispatch.
        'vendor_document_types' => 'vendors',
        // Metering (module 10) — a tariff prices a reading and nothing else reads it.
        'utility_tariffs' => 'utility_meters',
        // Payroll (module 24) — the dated statutory ladder EG-03 posts from. Deliberately NOT
        // `employees`: an operator can keep a staff register without running payroll here.
        'payroll_rates' => 'payrolls',
        // Owner accounting (module 15/20) — a disbursement pays out what a statement computed, so
        // they are one decision: does this operator settle with owners through Atriom at all.
        'disbursements' => 'owner_statements',
        // Bank reconciliation reads the accounts it matches against.
        'bank_statements' => 'bank_accounts',
        // The approval ladder: the bands and the queue that walks them.
        'approval_rules' => 'approvals',
    ];

    /**
     * Modules that are switched OFF at the code, not at the operator's discretion — unfinished work
     * that must not appear anywhere in a running system, with the reason it is parked.
     *
     * **This is stronger than the settings toggle and deliberately so.** `modules.eta` was already
     * defaulted false and a settings migration turned it off for existing installs, and ETA was
     * still *present*: an "ETA e-Invoicing" tab on the settings screen with two required fields, an
     * "ETA Status" column on every invoice list, "Submit invoices to the Egyptian Tax Authority" on
     * the roles matrix, ETA references on the invoice PDF, `eta_*` keys in the mobile API payload,
     * and a toggle inviting an operator to switch on a module that has never been certified. An
     * operator cannot tell "off" from "unfinished", and the toggle says the difference is theirs to
     * decide. It is not.
     *
     * **The key stays in {@see KEYS}.** A key outside that list is a guard that can never refuse —
     * `enabled()` returns true for anything unlisted — so removing `eta` here would turn every
     * `Modules::enabled('eta')` call site into a permanent *yes*, which is the exact opposite of
     * what freezing means. That mistake is silent: nothing errors, the module simply comes back on.
     *
     * **Why the settings row is not consulted at all.** A frozen module answers false whatever the
     * database says, so a stale row, a restored backup or a hand-edited `settings` table cannot put
     * an uncertified tax-authority integration back in front of an operator. It also means the one
     * place to look when the work resumes is this constant: delete the entry and every gated
     * surface returns intact.
     *
     * @var array<string, string> module key => why it is frozen
     */
    public const FROZEN = [
        'eta' => 'Module 16 (Egyptian Tax Authority e-invoicing) is incomplete and uncertified — mock '.
            'submissions only, no CAdES signing, no production credentials, and an issuer identity that '.
            'duplicates TaxSettings. Frozen 2026-08-22 at the owner\'s request so the work can be picked '.
            'up later; the services, job, config and tests are all kept, only the surfaces are gated. '.
            'Delete this entry to bring it back.',
    ];

    /**
     * Module keys an operator may actually toggle — everything except the frozen ones.
     *
     * The Settings screen builds its toggles from this, never from {@see KEYS}: a switch for a
     * module the code refuses to enable does nothing, and a control that does nothing is worse
     * than an absent one.
     *
     * @return string[]
     */
    public static function toggleable(): array
    {
        return array_values(array_diff(self::KEYS, array_keys(self::FROZEN)));
    }

    /** Is this module frozen in code, regardless of what the operator's settings say? */
    public static function frozen(string $module): bool
    {
        return array_key_exists($module, self::FROZEN);
    }

    public static function enabled(string $module): bool
    {
        // A catalogue answers whatever the module that owns it answers. Resolved FIRST, before the
        // frozen check and before KEYS, so a follower can never disagree with its owner.
        $module = self::FEATURE_OF[$module] ?? $module;

        if (self::frozen($module)) {
            return false;
        }

        if (! in_array($module, self::KEYS, true)) {
            // Core modules — always on.
            return true;
        }

        return (bool) (app(ModulesSettings::class)->{$module} ?? true);
    }

    /**
     * The section a module's switch is rendered under, or null for one nothing groups.
     *
     * Used by the Settings screen; also the thing that makes {@see GROUPS} and {@see KEYS} provably
     * the same set, which `ModulesAreSuperAdminOnlyTest` asserts.
     */
    public static function sectionOf(string $module): ?string
    {
        foreach (self::GROUPS as $section => $keys) {
            if (in_array($module, $keys, true)) {
                return $section;
            }
        }

        return null;
    }

    /**
     * The toggleable modules of one section, in the order the section declares them.
     *
     * @return string[]
     */
    public static function toggleableIn(string $section): array
    {
        return array_values(array_filter(
            self::GROUPS[$section] ?? [],
            fn (string $key): bool => ! self::frozen($key),
        ));
    }
}
