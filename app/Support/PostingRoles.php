<?php

namespace App\Support;

/**
 * The semantic posting roles the code asks for by name — the vocabulary of `AccountResolver`.
 *
 * **Why a registry and not free text.** `account_mappings.key` is a plain string, and a row whose
 * key is `rent_revenu` maps nothing: the resolver never asks for that spelling, so the typo does not
 * throw — it simply leaves the real role unmapped, and the operator sees a saved row that does
 * nothing. Listing the roles here makes the key a *picker* rather than a text box, which is the only
 * way an operator can be sure the row they wrote is a row the ledger will read.
 *
 * **The group is the statement class the role is MEANT for**, not the class of whatever account it
 * currently points at. It orders the screen and tells the accountant what kind of account belongs on
 * each row; it is deliberately advisory rather than enforced, because a real chart legitimately
 * disagrees in places — `deferred_rent` swings between an asset and a liability with the sign of the
 * straight-line adjustment, so refusing a liability account there would refuse a correct chart.
 *
 * Kept honest by `PostingRolesRegistryTest`, which asserts this list and the seeded global defaults
 * are the same set — so a role added to `AccountMappingSeeder` cannot ship without a home here.
 */
class PostingRoles
{
    public const GROUP_ASSET = 'asset';

    public const GROUP_LIABILITY = 'liability';

    public const GROUP_EQUITY = 'equity';

    public const GROUP_REVENUE = 'revenue';

    public const GROUP_EXPENSE = 'expense';

    /** @var array<string, string> role key => statement class it is meant for */
    public const ROLES = [
        // ---- Assets ----
        'cash' => self::GROUP_ASSET,
        'bank' => self::GROUP_ASSET,
        'accounts_receivable' => self::GROUP_ASSET,
        'employee_advances' => self::GROUP_ASSET,
        'custody' => self::GROUP_ASSET,
        'vat_recoverable' => self::GROUP_ASSET,
        'deferred_rent' => self::GROUP_ASSET,
        'furniture_equipment' => self::GROUP_ASSET,
        'accumulated_depreciation' => self::GROUP_ASSET,
        'inventory' => self::GROUP_ASSET,

        // ---- Liabilities ----
        'accounts_payable' => self::GROUP_LIABILITY,
        'deposits_held' => self::GROUP_LIABILITY,
        'vat_payable' => self::GROUP_LIABILITY,
        'accrued_expenses' => self::GROUP_LIABILITY,
        'salary_tax_payable' => self::GROUP_LIABILITY,
        'withholding_tax_payable' => self::GROUP_LIABILITY,
        'social_insurance_payable' => self::GROUP_LIABILITY,
        'employee_deductions_payable' => self::GROUP_LIABILITY,
        'unearned_revenue' => self::GROUP_LIABILITY,
        'due_to_owner' => self::GROUP_LIABILITY,
        'inventory_grni' => self::GROUP_LIABILITY,

        // ---- Equity ----
        'capital' => self::GROUP_EQUITY,
        'retained_earnings' => self::GROUP_EQUITY,
        'owner_distributions' => self::GROUP_EQUITY,

        // ---- Revenue ----
        'rent_revenue' => self::GROUP_REVENUE,
        'service_charge_revenue' => self::GROUP_REVENUE,
        'cam_recovery_revenue' => self::GROUP_REVENUE,
        'cam_admin_fee_revenue' => self::GROUP_REVENUE,
        'utility_revenue' => self::GROUP_REVENUE,
        'parking_revenue' => self::GROUP_REVENUE,
        'percentage_rent_revenue' => self::GROUP_REVENUE,
        'marketing_revenue' => self::GROUP_REVENUE,
        'late_fee_income' => self::GROUP_REVENUE,
        'misc_income' => self::GROUP_REVENUE,
        'sales_returns' => self::GROUP_REVENUE,
        'gain_on_disposal' => self::GROUP_REVENUE,

        // ---- Expenses ----
        'salaries_expense' => self::GROUP_EXPENSE,
        'social_insurance_expense' => self::GROUP_EXPENSE,
        'maintenance_expense' => self::GROUP_EXPENSE,
        'utilities_expense' => self::GROUP_EXPENSE,
        'cleaning_security_expense' => self::GROUP_EXPENSE,
        'marketing_expense' => self::GROUP_EXPENSE,
        'admin_expense' => self::GROUP_EXPENSE,
        'depreciation_expense' => self::GROUP_EXPENSE,
        'bad_debt_expense' => self::GROUP_EXPENSE,
        'inventory_adjustment' => self::GROUP_EXPENSE,
        'loss_on_disposal' => self::GROUP_EXPENSE,
        'bank_charges' => self::GROUP_EXPENSE,
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ROLES);
    }

    public static function group(string $key): ?string
    {
        return self::ROLES[$key] ?? null;
    }

    /** @return list<string> the role keys belonging to one statement class */
    public static function keysIn(string $group): array
    {
        return array_keys(array_filter(self::ROLES, fn (string $g) => $g === $group));
    }

    public static function label(string $key): string
    {
        return __("admin.posting_roles.{$key}");
    }

    public static function groupLabel(string $group): string
    {
        return __("admin.posting_role_groups.{$group}");
    }

    /**
     * Options for the key picker, grouped by statement class so the accountant reads the screen
     * the way they read a trial balance.
     *
     * @return array<string, array<string, string>>
     */
    public static function groupedOptions(): array
    {
        $out = [];

        foreach (self::ROLES as $key => $group) {
            $out[self::groupLabel($group)][$key] = self::label($key);
        }

        return $out;
    }

    /** Flat value => label map, for table formatting and filters. */
    public static function options(): array
    {
        $out = [];

        foreach (array_keys(self::ROLES) as $key) {
            $out[$key] = self::label($key);
        }

        return $out;
    }
}
