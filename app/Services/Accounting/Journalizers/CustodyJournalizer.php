<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\Custody;
use App\Services\Accounting\AccountResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Custody grant → GL (module 25, Treasury Phase 1):
 *
 *   Dr Custodies (amount)   / Cr Cash | Bank (per paid_from)
 *
 * Custodies is a dedicated asset (money in a custodian's hands) — NOT accounts
 * receivable, so the AR tie-out is unaffected. Dimensioned to the custody's
 * (denormalised) property. A soft-deleted custody has no ledger effect (sync voids).
 */
class CustodyJournalizer implements Journalizer
{
    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var Custody $custody */
        $custody = $source;

        $amount = round((float) $custody->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $assetId = $custody->asset_id;
        if (! $assetId) {
            return null;
        }

        $cashRole = $custody->paid_from === 'bank' ? 'bank' : 'cash';
        $name = $custody->employee()->withTrashed()->value('name') ?? '';

        return [
            'entry_date' => $custody->custody_date,
            'description_en' => 'Custody — '.$name,
            'description_ar' => 'عهدة — '.$name,
            'asset_id' => $assetId,
            'lines' => [
                ['ledger_account_id' => $this->accounts->id('custody', $assetId), 'debit' => $amount, 'credit' => 0, 'asset_id' => $assetId],
                ['ledger_account_id' => $this->accounts->id($cashRole, $assetId), 'debit' => 0, 'credit' => $amount, 'asset_id' => $assetId],
            ],
        ];
    }
}
