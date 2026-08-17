<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * What an OWNER may read in the operator's admin panel — and what is none of their business.
 *
 * Eltizam operates malls for Jawad. Those are two companies with a contract between them, and the
 * admin panel holds both companies' information. Until 2026-08-11 the `owner` role was granted
 * **every `.view` permission in the catalogue** on the reasoning that property isolation would keep
 * it honest. It does not, for two independent reasons:
 *
 *  - **Sixteen models are SHARED, not property-scoped** ({@see PropertyIsolation::sharedModels()}) — the
 *    vendor catalogue, the staff accounts, the SKU catalogue, the chart of accounts, the settings.
 *    "Scoped to the properties they own" simply does not apply to a row with no `asset_id`, so an
 *    owner browsing `/admin/vendors` saw Eltizam's entire supplier register **across every mall it
 *    operates, including a competing owner's**.
 *  - **Property scope is the wrong axis for some of it anyway.** Eltizam's payroll for staff
 *    assigned to Jawad's mall is still Eltizam's payroll. Narrowing it to one property does not
 *    make salaries the landlord's information.
 *
 * So visibility is a per-module decision, and this is where it is recorded. `RolesPermissionsSeeder`
 * filters the owner grant through {@see allows()} — the one call — and
 * `OwnerVisibilityConformanceTest` fails the build when a permission group is unclassified, so a
 * new module forces the decision instead of inheriting "the owner sees it".
 *
 * **The default is deliberately NOT fail-closed-and-forget.** An allow-list alone goes stale in the
 * other direction: ship a new property module, forget to list it, and the owner silently loses
 * oversight they are contractually entitled to. Classifying *both* sides and gating on
 * completeness is what catches drift in both directions — the same shape as
 * {@see DeletionPolicy} and {@see PropertyIsolation}.
 *
 * **Benchmark.** Yardi's owner/investor portal exposes financials, rent roll, occupancy, budget vs
 * actual, distributions and property AP; it does not expose the manager's payroll, staff accounts,
 * other owners' data, or the manager's own bank accounts. This registry follows that, with one
 * stated deviation: **the vendor register is withheld** even though a generous reading of Yardi
 * would share it. The owner still sees which vendor did what on their property — that is on the
 * bill and the work order — but the register itself is a portfolio-wide supplier list with other
 * owners' contracts in it.
 */
class OwnerVisibility
{
    /**
     * Permission groups an owner may READ, each with the reason it is theirs to see.
     *
     * Read-only throughout: the seeder only ever passes `.view` / `.view_all` / `reports.download`
     * through this filter, so listing a group here grants oversight, never authority.
     *
     * @var array<string, string>
     */
    public const VISIBLE = [
        // ---- The property itself ----
        'assets' => 'The property they own.',
        'units' => 'The lettable space in it. (Floors have no permission group of their own — they are managed under the property.)',
        'areas' => 'The zones of their property — and the basis of a zone-scoped recovery pool.',
        'rentable_items' => 'Parking, storage and signage are let, so they are the owner\'s income too.',
        'fixed_assets' => 'The plant that stands in their mall — chillers, escalators, generators.',

        // ---- Who trades there, on what terms ----
        'tenants' => 'Who trades in their mall. The resource is occupancy-scoped, so this is their tenants.',
        'leases' => 'The contracts that generate the income the owner is paid from.',
        'tenant_sales' => 'Declared turnover — the basis of percentage rent, which is the owner\'s money.',
        'announcements' => 'What their tenants were told, and when.',
        'violations' => 'Enforcement on their property.',

        // ---- Their money ----
        'invoices' => 'The AR raised against their property.',
        'payments' => 'What was actually collected.',
        'credit_notes' => 'What was given back, and why.',
        'deposit_transactions' => 'Security deposits held under their leases.',
        'post_dated_cheques' => 'Cheques held against their leases.',
        'cam' => 'The recovery pool their tenants fund and the annual true-up.',
        'utility_meters' => 'Consumption recharged on their property.',
        'utility_tariffs' => 'The published price behind a utility recharge on their property — the number that explains the figure.',
        'expenses' => 'Property opex — the statement charges it to them, so they may see it.',
        'vendor_bills' => 'Property AP, for the same reason: it lands in their statement. Yardi owner portals expose property AP too.',
        'marketing' => 'The fund 5% of their base rent pays into.',
        'marketing_posts' => 'The shopper-facing output that fund buys.',

        // ---- The books and the deliverable ----
        'general_ledger' => 'Their property\'s books; the GL carries `asset_id` as a dimension.',
        'journal_entries' => 'The entries behind those books.',
        'reports' => 'Property reporting, including the download.',
        'owner_statements' => 'The deliverable. `owner_statements.view_own` is what the owner-facing page gates on.',
        'disbursements' => 'Their money going out to them.',
        'owner_requests' => 'Their own requests to the operator — the one thing they may also create.',

        // ---- Their property being looked after ----
        'requests' => 'The request board for their property.',
        'facility' => 'The plans that keep their plant running.',
    ];

    /**
     * Groups that are ELTIZAM'S OWN BUSINESS, each with the reason the owner does not get it.
     *
     * @var array<string, string>
     */
    public const OPERATOR_INTERNAL = [
        // ---- The operator's counterparties ----
        // Deliberately internal FOR NOW, and it is a decision rather than an oversight: the
        // register names the operator's buyers, their contract numbers and their purchase
        // prices. It becomes owner-visible when phase 5 makes unit ownership change the owner's
        // OWN numbers — at that point withholding it would hide the reason his statement moved.
        'unit_ownerships' => 'Who bought which unit, at what price, on whose contract. Reclassify to VISIBLE with phase 5, when a sold unit starts changing what the property owner is paid.',

        // ---- The operator's people and pay ----
        'payrolls' => 'Eltizam\'s salary bill. Assigning a member of staff to Jawad\'s mall does not make their salary Jawad\'s information.',
        'employees' => 'Eltizam\'s staff records — names, contracts, national IDs, salary structures.',
        'departments' => 'Eltizam\'s internal org structure.',
        'users' => 'Every staff account in the system. `UserResource` is SHARED and unscoped, so this was the whole company, not one mall.',
        'roles' => 'The permission model itself. An owner reading it learns exactly what each operator role may do.',

        // ---- The operator's own money and stock ----
        'bank_accounts' => 'Eltizam\'s own bank accounts, not the property\'s.',
        'custodies' => 'عهدة — the operator\'s petty cash floats and who holds them.',
        'inventory' => 'A SHARED SKU catalogue plus Eltizam\'s own stores; nothing here is per-property.',
        'procurement' => 'Eltizam\'s purchasing process — requisitions, quotes, who it chose not to buy from.',
        'vendors' => 'The SHARED supplier register: rates and contracts spanning every mall Eltizam runs, including a competing owner\'s. The owner still sees which vendor did the work, on the bill and the work order. This is the one place we are stricter than a generous reading of Yardi, deliberately.',
        'approvals' => 'Eltizam\'s internal authority ladder — who may commit the company to what.',

        // ---- Configuration and audit ----
        'settings' => 'System configuration, including tax rates, integration credentials and security policy.',
        'activity_log' => 'Who did what across the operator\'s whole business, not just this property.',
        'imports' => 'A data-loading capability, not an oversight surface.',
        'account_mappings' => 'Posting configuration — which account each money source lands in.',
        'ledger_accounts' => 'The SHARED chart of accounts; the owner sees the resulting books, not the configuration behind them.',
        'accounting_periods' => 'The operator\'s own close calendar.',
        'charge_codes' => 'The SHARED portfolio billing vocabulary and its VAT rulings.',
        'tax_codes' => 'The tax catalogue and its dated rates — the accountant\'s configuration, portfolio-wide. The owner sees the tax CHARGED on their property\'s invoices, not the table it is resolved from.',
        'notes' => 'SHARED polymorphic internal commentary. A note about a tenant is exactly the kind of thing an operator must be able to write without the landlord reading it.',
    ];

    /**
     * May an owner hold this permission?
     *
     * Unclassified groups are refused. That is the safe direction for a leak, and the conformance
     * gate turns the build red rather than letting the refusal go unnoticed as lost oversight.
     */
    public static function allows(string $permission): bool
    {
        return array_key_exists(self::group($permission), self::VISIBLE);
    }

    /** The module a permission belongs to — `invoices.view_all` → `invoices`. */
    public static function group(string $permission): string
    {
        return Str::beforeLast($permission, '.');
    }

    /**
     * Groups present in the catalogue but classified in neither list — what the gate fails on.
     *
     * @param  iterable<string>  $permissions
     * @return array<int, string>
     */
    public static function unclassified(iterable $permissions): array
    {
        $known = self::VISIBLE + self::OPERATOR_INTERNAL;

        return collect($permissions)
            ->map(fn (string $p): string => self::group($p))
            ->unique()
            ->reject(fn (string $g): bool => array_key_exists($g, $known))
            ->values()
            ->all();
    }

    /**
     * Groups classified here that no longer exist in the catalogue.
     *
     * A stale entry is not harmless: it reads as a considered decision about a module, and the next
     * person to add a module by that name inherits it silently.
     *
     * @param  iterable<string>  $permissions
     * @return array<int, string>
     */
    public static function stale(iterable $permissions): array
    {
        $live = collect($permissions)->map(fn (string $p): string => self::group($p))->unique()->all();

        return collect(array_keys(self::VISIBLE + self::OPERATOR_INTERNAL))
            ->reject(fn (string $g): bool => in_array($g, $live, true))
            ->values()
            ->all();
    }
}
