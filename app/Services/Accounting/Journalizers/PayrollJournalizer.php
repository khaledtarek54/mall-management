<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\Payroll;
use App\Services\Accounting\AccountResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Payroll run (مسير رواتب):
 *   Dr Salaries Expense (gross)
 *   Cr Salary Tax Payable (withheld) + Cr Social Insurance Payable (withheld)
 *   Cr Cash / Bank (net paid)
 *
 * Withheld tax + insurance become liabilities to remit later. Posts an `approved`
 * run; drafts/cancelled are skipped (sync voids any entry).
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
        $net = round((float) $payroll->net_paid, 2); // = gross − tax − insurance (model-enforced)

        if ($gross <= 0) {
            return null;
        }

        // Deductions exceed gross (malformed) → net negative. Skip + flag rather than
        // emit an unbalanced entry.
        if ($net < 0) {
            \Illuminate\Support\Facades\Log::warning(
                "PayrollJournalizer: run {$payroll->number} deductions exceed gross (net {$net}); skipping ledger post."
            );

            return null;
        }

        $cashRole = $payroll->paid_from === 'cash' ? 'cash' : 'bank';

        $lines = [[
            'ledger_account_id' => $this->accounts->id('salaries_expense', $assetId),
            'debit' => $gross,
            'credit' => 0,
            'asset_id' => $assetId,
        ]];

        if ($tax > 0) {
            $lines[] = ['ledger_account_id' => $this->accounts->id('salary_tax_payable', $assetId), 'debit' => 0, 'credit' => $tax, 'asset_id' => $assetId];
        }
        if ($insurance > 0) {
            $lines[] = ['ledger_account_id' => $this->accounts->id('social_insurance_payable', $assetId), 'debit' => 0, 'credit' => $insurance, 'asset_id' => $assetId];
        }
        if ($net > 0) {
            $lines[] = ['ledger_account_id' => $this->accounts->id($cashRole, $assetId), 'debit' => 0, 'credit' => $net, 'asset_id' => $assetId];
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
