<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\EmployeeAdvanceRepayment;
use App\Services\Accounting\AccountResolver;
use App\Support\MoneyAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * Repayment of an employee advance/loan → GL (module 24, Phase 2):
 *
 *   Dr the rail's account (PaymentMethod)   / Cr Employee Advances (amount)
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

        return [
            'entry_date' => $repayment->repaid_on,
            'description_en' => 'Employee advance repayment',
            'description_ar' => 'سداد سلفة موظف',
            // The narrative is a KEY resolved at READ time (EG-36); the prose above stays as
            // the snapshot and the floor for anything that does not go through the resolver.
            'description_key' => 'employee_advance.repaid',
            'description_data' => [],
            'asset_id' => $assetId,
            'lines' => [
                // The RAIL names its account. This was the seventh journalizer carrying the mirror
                // ternary EG-11 removed from the other six, so an InstaPay repayment debited CASH.
                ['ledger_account_id' => MoneyAccount::for(null, $repayment->method, $assetId, $this->accounts), 'debit' => $amount, 'credit' => 0, 'asset_id' => $assetId],
                ['ledger_account_id' => $this->accounts->id('employee_advances', $assetId), 'debit' => 0, 'credit' => $amount, 'asset_id' => $assetId],
            ],
        ];
    }
}
