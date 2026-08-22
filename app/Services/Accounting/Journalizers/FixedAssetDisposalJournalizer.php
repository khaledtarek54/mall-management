<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\FixedAssetDisposal;
use App\Services\Accounting\AccountResolver;
use App\Support\MoneyAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixed-asset disposal write-off → GL (module 23, Phase 2b). Removes the asset from
 * the balance sheet and recognises the gain/loss on disposal:
 *
 *   Dr Accumulated Depreciation (accumulated to date)
 *   Dr Cash | Bank              (sale proceeds, if any)
 *   Dr Loss on Disposal         (if net book value > proceeds)
 *       Cr Furniture & Equipment (gross acquisition cost)
 *       Cr Gain on Disposal      (if proceeds > net book value)
 *
 * Net book value = cost − accumulated; gain/loss = proceeds − NBV. Together with the
 * (retained) acquisition + depreciation entries, this nets Furniture & Equipment and
 * Accumulated Depreciation back to zero for the disposed asset. Touches neither AR nor
 * AP — tie-out-safe. Dimensioned to the asset's property; voids with the asset (the
 * parent-trashed relation returns null → LedgerPoster::sync voids it).
 */
class FixedAssetDisposalJournalizer implements Journalizer
{
    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var FixedAssetDisposal $disposal */
        $disposal = $source;

        // Soft-delete-aware: a trashed parent → null → the disposal entry voids.
        $asset = $disposal->fixedAsset;
        if (! $asset) {
            return null;
        }

        $assetId = $asset->asset_id;
        if (! $assetId) {
            return null;
        }

        $cost = round((float) $asset->acquisition_cost, 2);
        // The SAME definition the service uses — a legacy asset's write-off happened before
        // Atriom existed and still reduces the carrying amount this gain or loss is measured against.
        $accumulated = $asset->accumulatedDepreciation();
        $proceeds = round((float) $disposal->proceeds, 2);
        $nbv = round($cost - $accumulated, 2);
        $gainLoss = round($proceeds - $nbv, 2); // + gain, − loss

        $lines = [];

        // Reverse the gross cost off the books.
        if ($cost > 0) {
            $lines[] = ['ledger_account_id' => $this->accounts->id('furniture_equipment', $assetId), 'debit' => 0, 'credit' => $cost, 'asset_id' => $assetId];
        }
        // Reverse the accumulated depreciation.
        if ($accumulated > 0) {
            $lines[] = ['ledger_account_id' => $this->accounts->id('accumulated_depreciation', $assetId), 'debit' => $accumulated, 'credit' => 0, 'asset_id' => $assetId];
        }
        // Sale proceeds in.
        if ($proceeds > 0) {
            $lines[] = ['ledger_account_id' => MoneyAccount::for(null, $disposal->proceeds_account, $assetId, $this->accounts), 'debit' => $proceeds, 'credit' => 0, 'asset_id' => $assetId];
        }
        // Balancing gain or loss.
        if ($gainLoss > 0) {
            $lines[] = ['ledger_account_id' => $this->accounts->id('gain_on_disposal', $assetId), 'debit' => 0, 'credit' => $gainLoss, 'asset_id' => $assetId];
        } elseif ($gainLoss < 0) {
            $lines[] = ['ledger_account_id' => $this->accounts->id('loss_on_disposal', $assetId), 'debit' => abs($gainLoss), 'credit' => 0, 'asset_id' => $assetId];
        }

        if (empty($lines)) {
            return null; // a zero-cost, zero-proceeds disposal has no GL effect
        }

        return [
            'entry_date' => $disposal->disposed_on,
            'description_en' => 'Fixed asset disposed — '.$asset->name,
            'description_ar' => 'استبعاد أصل ثابت — '.$asset->name,
            // The narrative is a KEY resolved at READ time (EG-36); the prose above stays as
            // the snapshot and the floor for anything that does not go through the resolver.
            'description_key' => 'fixed_asset.disposed',
            'description_data' => ['asset' => $asset->name],
            'asset_id' => $assetId,
            'lines' => $lines,
        ];
    }
}
