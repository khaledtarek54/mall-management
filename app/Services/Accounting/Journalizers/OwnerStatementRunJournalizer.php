<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\OwnerStatementRun;
use App\Services\Accounting\AccountResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Owner statement run (كشف حساب المالك) → the distribution accrual:
 *   Dr Owner Distributions (contra-equity draw) / Cr Due to Owner (a liability)
 * for the property's `net_distributable` — the money the owner is owed for the period.
 * A disbursement later clears Due to Owner against Bank (see DisbursementJournalizer).
 *
 * Posts ONLY a finalised run; a draft or superseded run returns null (so the sweep voids a
 * superseded run's entry — the Revise self-heal). Reads only its own row (`net_distributable`
 * was frozen at finalise), so nothing on a child is walked at post time. v1 has no management
 * fee, so for a sole full-tenure owner `net_distributable` equals the income statement's net.
 */
class OwnerStatementRunJournalizer implements Journalizer
{
    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var OwnerStatementRun $run */
        $run = $source;

        if ($run->status !== OwnerStatementRun::STATUS_FINALISED) {
            return null; // draft / superseded → no GL effect
        }

        $amount = round((float) $run->net_distributable, 2);
        if ($amount <= 0) {
            return null; // nothing distributable (no owner, or a loss/zero period) → no accrual
        }

        $assetId = $run->asset_id;
        $distributions = $this->accounts->id('owner_distributions', $assetId);
        $dueToOwner = $this->accounts->id('due_to_owner', $assetId);

        return [
            'entry_date' => $run->posting_date,
            'description_en' => 'Owner statement '.$run->reference,
            'description_ar' => 'كشف حساب المالك '.$run->reference,
            'asset_id' => $assetId,
            'lines' => [
                ['ledger_account_id' => $distributions, 'debit' => $amount, 'credit' => 0, 'asset_id' => $assetId],
                ['ledger_account_id' => $dueToOwner, 'debit' => 0, 'credit' => $amount, 'asset_id' => $assetId],
            ],
        ];
    }
}
