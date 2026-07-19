<?php

namespace Database\Seeders;

use App\Models\AccountMapping;
use App\Models\LedgerAccount;
use Illuminate\Database\Seeder;

/**
 * ربط الحسابات الافتراضي — default semantic role → chart account mappings, used
 * by the Phase-1 posting recipes. Each row maps a code-level role (never a hard
 * account number in code) to a postable account in the starter chart. The
 * accountant can re-point any role from the UI without touching code.
 */
class AccountMappingSeeder extends Seeder
{
    /** @var array<string, string> role => account code */
    private const MAP = [
        'cash' => '11101001',
        'bank' => '11102001',
        'accounts_receivable' => '11201001',
        // Employee advances/loans (module 24, Phase 2) — a receivable (money owed BY staff).
        'employee_advances' => '11203001',
        // Custodies (عهدة — module 25) — money in a custodian's hands (an asset), settled
        // by expenses (with receipts) or returned as cash.
        'custody' => '11204001',
        'accounts_payable' => '21101001',
        'deposits_held' => '21201001',
        'vat_payable' => '21301001',
        'vat_recoverable' => '11401001',
        'accrued_expenses' => '21401001',
        'salary_tax_payable' => '21302001',
        'social_insurance_payable' => '21601001',
        'unearned_revenue' => '21501001',
        'capital' => '31101001',
        'retained_earnings' => '32101001',
        // Owner statements + disbursements (module 27). `owner_distributions` is the
        // contra-equity draw debited when a statement is finalised; `due_to_owner` is the
        // liability it credits, cleared Dr due_to_owner / Cr bank when the owner is paid.
        'owner_distributions' => '34101001',
        'due_to_owner' => '21802001',
        'rent_revenue' => '41101001',
        'service_charge_revenue' => '41102001',
        'cam_recovery_revenue' => '41103001',
        'cam_admin_fee_revenue' => '41108001',
        'utility_revenue' => '41104001',
        'percentage_rent_revenue' => '41105001',
        'marketing_revenue' => '41106001',
        'late_fee_income' => '41107001',
        'misc_income' => '42101001',
        'sales_returns' => '43101001',
        'salaries_expense' => '51101001',
        'maintenance_expense' => '51102001',
        'utilities_expense' => '51103001',
        'cleaning_security_expense' => '51104001',
        'marketing_expense' => '51105001',
        'admin_expense' => '51106001',
        'depreciation_expense' => '51107001',
        // Fixed assets (module 23, Phase 2): acquisition Dr Furniture & Equipment /
        // Cr Cash|Bank; monthly charge Dr Depreciation Expense / Cr Accumulated
        // Depreciation (a contra-asset). Neither touches AR/AP — tie-out-safe.
        'furniture_equipment' => '12101001',
        'accumulated_depreciation' => '12201001',
        // Disposal write-off (Phase 2b): gain (other income) / loss (other expense).
        'gain_on_disposal' => '42102001',
        'loss_on_disposal' => '52102001',
        'inventory' => '11301001',
        'inventory_adjustment' => '51108001',
        // Receipts credit a dedicated clearing liability (NOT the AP control that the
        // reconcile harness ties out to vendor bills) — goods received, not yet invoiced.
        'inventory_grni' => '21701001',
        'bank_charges' => '52101001',
    ];

    public function run(): void
    {
        $idByCode = LedgerAccount::query()
            ->whereIn('code', array_values(self::MAP))
            ->pluck('id', 'code');

        foreach (self::MAP as $key => $code) {
            $accountId = $idByCode[$code] ?? null;
            if (! $accountId) {
                // Chart account missing — skip rather than seed a dangling mapping.
                continue;
            }

            AccountMapping::updateOrCreate(
                ['key' => $key, 'asset_id' => null],
                ['ledger_account_id' => $accountId],
            );
        }
    }
}
