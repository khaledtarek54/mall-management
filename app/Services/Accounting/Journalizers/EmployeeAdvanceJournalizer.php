<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\EmployeeAdvance;
use App\Services\Accounting\AccountResolver;
use App\Support\MoneyAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * Employee advance/loan grant → GL (module 24, Phase 2):
 *
 *   Dr Employee Advances (amount)   / Cr the rail's account (PaymentMethod, per paid_from)
 *
 * Employee Advances is a receivable (money owed BY the employee) — NOT accounts
 * receivable (which ties out to tenant invoices), so the AR tie-out is unaffected.
 * Dimensioned to the advance's (denormalised) property. A soft-deleted advance has no
 * ledger effect — LedgerPoster::sync voids its entry.
 */
class EmployeeAdvanceJournalizer implements Journalizer
{
    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var EmployeeAdvance $advance */
        $advance = $source;

        $amount = round((float) $advance->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $assetId = $advance->asset_id;
        if (! $assetId) {
            return null;
        }

        // Resolve the name withTrashed so an archived employee still labels the entry.
        $name = $advance->employee()->withTrashed()->value('name') ?? '';

        return [
            'entry_date' => $advance->advance_date,
            'description_en' => 'Employee advance — '.$name,
            'description_ar' => 'سلفة موظف — '.$name,
            // The narrative is a KEY resolved at READ time (EG-36); the prose above stays as
            // the snapshot and the floor for anything that does not go through the resolver.
            'description_key' => 'employee_advance.granted',
            'description_data' => ['name' => $name],
            'asset_id' => $assetId,
            'lines' => [
                ['ledger_account_id' => $this->accounts->id('employee_advances', $assetId), 'debit' => $amount, 'credit' => 0, 'asset_id' => $assetId],
                // The RAIL names its account — the eighth journalizer carrying the mirror ternary
                // EG-11 removed from the other six, so an InstaPay advance credited CASH.
                ['ledger_account_id' => MoneyAccount::for(null, $advance->paid_from, $assetId, $this->accounts), 'debit' => 0, 'credit' => $amount, 'asset_id' => $assetId],
            ],
        ];
    }
}
