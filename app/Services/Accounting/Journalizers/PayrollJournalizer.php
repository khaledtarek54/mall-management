<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\PaymentMethod;
use App\Models\Payroll;
use App\Services\Accounting\AccountResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Payroll run (مسير رواتب):
 *   Dr Salaries Expense (gross, incl. allowances)
 *   Dr Social Insurance Expense (employer share — module 24 Phase 4a)
 *   Cr Salary Tax Payable (withheld) + Cr Social Insurance Payable (employee + employer share)
 *   Cr Employee Advances (advance installments withheld from pay — module 24 Phase 4b)
 *   Cr Employee Deductions Payable (ad-hoc / penalty deductions — module 24 Phase 4c)
 *   Cr Cash / Bank (net paid)
 *
 * Withheld tax + insurance become liabilities to remit later. The EMPLOYER social-insurance
 * contribution is a company cost that does NOT reduce net pay — it's a balanced Dr Expense /
 * Cr Payable pair. An ADVANCE installment (Cr Employee Advances) and an ad-hoc deduction
 * (Cr Employee Deductions Payable) are BOTH withheld from net. The entry stays balanced:
 *   Dr gross + employer_si  =  Cr tax + (emp_si + employer_si) + advance + other + net,
 * where net = gross − tax − emp_si − advance − other.
 * Posts an `approved` run; drafts/cancelled are skipped (sync voids any entry).
 */
class PayrollJournalizer implements Journalizer
{
    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var Payroll $payroll */
        $payroll = $source;

        if (! $payroll->isPostable()) {
            return null;
        }

        $assetId = $payroll->asset_id;
        $gross = round((float) $payroll->gross_salaries, 2);
        $tax = round((float) $payroll->salary_tax, 2);
        $insurance = round((float) $payroll->social_insurance, 2);
        $advanceDeductions = round((float) $payroll->advance_deductions, 2);
        $otherDeductions = round((float) $payroll->other_deductions, 2);
        $employerInsurance = round((float) $payroll->employer_social_insurance, 2);
        $net = round((float) $payroll->net_paid, 2); // = gross − tax − insurance − advance − other (model-enforced)

        if ($gross <= 0) {
            return null;
        }

        // Deductions exceed gross (malformed) → net negative. Skip + flag rather than
        // emit an unbalanced entry.
        if ($net < 0) {
            Log::warning(
                "PayrollJournalizer: run {$payroll->number} deductions exceed gross (net {$net}); skipping ledger post."
            );

            return null;
        }

        // The rail decides the account; null takes the floor. See PaymentJournalizer.
        $cashAccountId = PaymentMethod::accountIdOrFloor($payroll->paid_from, $assetId, $this->accounts);

        $lines = [[
            'ledger_account_id' => $this->accounts->id('salaries_expense', $assetId),
            'debit' => $gross,
            'credit' => 0,
            'asset_id' => $assetId,
        ]];

        // Employer social-insurance contribution (Phase 4a): a company cost that does NOT
        // reduce net pay — Dr Social Insurance Expense (added to Dr side) / and its Cr goes
        // to the SAME Social Insurance Payable as the employee share below (total owed to
        // the authority = employee + employer). Both legs added → entry stays balanced.
        if ($employerInsurance > 0) {
            $lines[] = ['ledger_account_id' => $this->accounts->id('social_insurance_expense', $assetId), 'debit' => $employerInsurance, 'credit' => 0, 'asset_id' => $assetId];
        }

        if ($tax > 0) {
            $lines[] = ['ledger_account_id' => $this->accounts->id('salary_tax_payable', $assetId), 'debit' => 0, 'credit' => $tax, 'asset_id' => $assetId];
        }
        // Employee withholding + employer contribution both credit the SI payable.
        $insurancePayable = round($insurance + $employerInsurance, 2);
        if ($insurancePayable > 0) {
            $lines[] = ['ledger_account_id' => $this->accounts->id('social_insurance_payable', $assetId), 'debit' => 0, 'credit' => $insurancePayable, 'asset_id' => $assetId];
        }
        // Advance installments withheld from pay repay the loan — Cr Employee Advances (the same
        // receivable the grant debited), so it nets back toward zero without a cash movement.
        if ($advanceDeductions > 0) {
            $lines[] = ['ledger_account_id' => $this->accounts->id('employee_advances', $assetId), 'debit' => 0, 'credit' => $advanceDeductions, 'asset_id' => $assetId];
        }
        // Ad-hoc / penalty deductions (خصومات) withheld from pay → a holding liability the
        // accountant reclassifies (penalties fund / other income / expense-reduction).
        if ($otherDeductions > 0) {
            $lines[] = ['ledger_account_id' => $this->accounts->id('employee_deductions_payable', $assetId), 'debit' => 0, 'credit' => $otherDeductions, 'asset_id' => $assetId];
        }
        if ($net > 0) {
            $lines[] = ['ledger_account_id' => $cashAccountId, 'debit' => 0, 'credit' => $net, 'asset_id' => $assetId];
        }

        return [
            'entry_date' => $payroll->period_month,
            'description_en' => 'Payroll '.$payroll->number,
            'description_ar' => 'رواتب '.$payroll->number,
            'asset_id' => $assetId,
            'lines' => $lines,
        ];
    }
}
