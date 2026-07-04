<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\CustodyTransaction;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\Journalizers\Concerns\MapsExpenseCategory;
use Illuminate\Database\Eloquent\Model;

/**
 * Custody settlement → GL (module 25, Treasury Phase 1). Reduces the Custodies asset:
 *
 *   expense  Dr Expense (by category)   / Cr Custodies   (the custodian spent + gave a receipt)
 *   return   Dr Cash | Bank (per method) / Cr Custodies   (the custodian returned unspent cash)
 *
 * The expense category maps to a P&L account via the shared MapsExpenseCategory trait
 * (same map as vendor bills / direct expenses). Dimensioned to the (denormalised)
 * property. Voids with the custody (the parent-lifecycle cascade soft-deletes settlements).
 */
class CustodyTransactionJournalizer implements Journalizer
{
    use MapsExpenseCategory;

    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var CustodyTransaction $txn */
        $txn = $source;

        $amount = round((float) $txn->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $assetId = $txn->asset_id;
        if (! $assetId) {
            return null;
        }

        // The debit side depends on the settlement kind.
        if ($txn->type === 'return') {
            $debitRole = $txn->method === 'bank' ? 'bank' : 'cash';
            $descEn = 'Custody return';
            $descAr = 'رد عهدة';
        } else { // expense
            $debitRole = $this->expenseRoleFor($txn->category ?? 'other', 'CustodyTransaction #'.$txn->id);
            $descEn = 'Custody expense — '.($txn->category ?? 'other');
            $descAr = 'مصروف عهدة';
        }

        return [
            'entry_date' => $txn->transaction_date,
            'description_en' => $descEn,
            'description_ar' => $descAr,
            'asset_id' => $assetId,
            'lines' => [
                ['ledger_account_id' => $this->accounts->id($debitRole, $assetId), 'debit' => $amount, 'credit' => 0, 'asset_id' => $assetId],
                ['ledger_account_id' => $this->accounts->id('custody', $assetId), 'debit' => 0, 'credit' => $amount, 'asset_id' => $assetId],
            ],
        ];
    }
}
