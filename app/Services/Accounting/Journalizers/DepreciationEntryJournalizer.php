<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\DepreciationEntry;
use App\Services\Accounting\AccountResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Monthly depreciation charge → GL (module 23, Phase 2). One entry per
 * depreciation_entry (asset × month):
 *
 *   Dr Depreciation Expense (amount)   / Cr Accumulated Depreciation (amount)
 *
 * Dimensioned to the asset's property. Accumulated Depreciation is a contra-asset,
 * so this reduces net book value on the balance sheet while the P&L carries the
 * charge. Touches neither AR nor AP — tie-out-safe.
 *
 * The parent fixed asset is resolved via the (soft-delete-aware) relation: if the
 * register row was deleted, `fixedAsset` is null and this returns null, so the
 * charge voids alongside the acquisition entry (deleting an asset removes its whole
 * GL footprint). A merely *disposed* asset is NOT trashed, so its historical
 * depreciation stays on the books — correct.
 */
class DepreciationEntryJournalizer implements Journalizer
{
    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var DepreciationEntry $entry */
        $entry = $source;

        $amount = round((float) $entry->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        // Excludes a soft-deleted parent (SoftDeletes global scope) → null → voids.
        $assetId = $entry->fixedAsset?->asset_id;
        if (! $assetId) {
            return null;
        }

        return [
            'entry_date' => $entry->period_month,
            'description_en' => 'Depreciation — '.($entry->fixedAsset?->name ?? ''),
            'description_ar' => 'إهلاك — '.($entry->fixedAsset?->name ?? ''),
            // The narrative is a KEY resolved at READ time (EG-36); the prose above stays as
            // the snapshot and the floor for anything that does not go through the resolver.
            'description_key' => 'depreciation.posted',
            'description_data' => ['asset' => $entry->fixedAsset?->name],
            'asset_id' => $assetId,
            'lines' => [
                ['ledger_account_id' => $this->accounts->id('depreciation_expense', $assetId), 'debit' => $amount, 'credit' => 0, 'asset_id' => $assetId],
                ['ledger_account_id' => $this->accounts->id('accumulated_depreciation', $assetId), 'debit' => 0, 'credit' => $amount, 'asset_id' => $assetId],
            ],
        ];
    }
}
