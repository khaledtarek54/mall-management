<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\FixedAsset;
use App\Services\Accounting\AccountResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixed-asset acquisition → GL (module 23, Phase 2). Capitalises the asset:
 *
 *   Dr Furniture & Equipment (acquisition_cost)   / Cr Cash | Bank (per funded_from)
 *
 * Credits CASH or BANK directly — NOT Accounts Payable — because most fixed assets
 * are paid on acquisition and the reconcile harness ties AP out to vendor-bill
 * balances (a fixed asset has no vendor bill). This mirrors the GRNI decision on the
 * inventory side: never post to a tied-out control account without its sub-ledger doc.
 *
 * The acquisition entry stays on the books while the asset is `active` OR `disposed`
 * (a disposed-but-not-written-off asset still shows gross cost + accumulated
 * depreciation until the disposal write-off is journalized — a future sub-phase). A
 * soft-deleted (mistaken) asset has no ledger effect — LedgerPoster::sync voids it.
 */
class FixedAssetAcquisitionJournalizer implements Journalizer
{
    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var FixedAsset $asset */
        $asset = $source;

        $amount = round((float) $asset->acquisition_cost, 2);
        if ($amount <= 0) {
            return null; // a zero-cost asset has no GL effect
        }

        $assetId = $asset->asset_id;
        if (! $assetId) {
            return null;
        }

        $cashRole = $asset->funded_from === 'bank' ? 'bank' : 'cash';

        return [
            'entry_date' => $asset->acquisition_date,
            'description_en' => 'Fixed asset acquired — '.$asset->name,
            'description_ar' => 'شراء أصل ثابت — '.$asset->name,
            'asset_id' => $assetId,
            'lines' => [
                ['ledger_account_id' => $this->accounts->id('furniture_equipment', $assetId), 'debit' => $amount, 'credit' => 0, 'asset_id' => $assetId],
                ['ledger_account_id' => $this->accounts->id($cashRole, $assetId), 'debit' => 0, 'credit' => $amount, 'asset_id' => $assetId],
            ],
        ];
    }
}
