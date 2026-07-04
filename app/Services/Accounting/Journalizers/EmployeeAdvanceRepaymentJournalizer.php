<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\EmployeeAdvanceRepayment;
use App\Services\Accounting\AccountResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Repayment of an employee advance/loan → GL (module 24, Phase 2):
 *
 *   Dr Cash | Bank (per method)   / Cr Employee Advances (amount)
 *
 * Reduces the Employee Advances receivable as the staff member pays back. Dimensioned
 * to the (denormalised) property. Voids with the advance (the parent-lifecycle cascade
 * soft-deletes repayments → LedgerPoster::sync voids their entries).
 */
class EmployeeAdvanceRepaymentJournalizer implements Journalizer
{
    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var EmployeeAdvanceRepayment $repayment */
        $repayment = $source;

        $amount = round((float) $repayment->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $assetId = $repayment->asset_id;
        if (! $assetId) {
            return null;
        }

        $cashRole = $repayment->method === 'bank' ? 'bank' : 'cash';

        return [
            'entry_date' => $repayment->repaid_on,
            'description_en' => 'Employee advance repayment',
            'description_ar' => 'سداد سلفة موظف',
            'asset_id' => $assetId,
            'lines' => [
                ['ledger_account_id' => $this->accounts->id($cashRole, $assetId), 'debit' => $amount, 'credit' => 0, 'asset_id' => $assetId],
                ['ledger_account_id' => $this->accounts->id('employee_advances', $assetId), 'debit' => 0, 'credit' => $amount, 'asset_id' => $assetId],
            ],
        ];
    }
}
