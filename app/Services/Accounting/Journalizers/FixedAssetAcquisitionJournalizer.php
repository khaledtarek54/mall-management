<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\FixedAsset;
use App\Services\Accounting\AccountResolver;
use App\Support\MoneyAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixed-asset acquisition → GL (module 23, Phase 2). Capitalises the asset:
 *
 *   Dr Furniture & Equipment (acquisition_cost)   / Cr the asset's bank account | the rail's account | Cash or Bank by role (per bank_account_id, then funded_from)
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

        // An asset loaded at cut-over was bought before this system existed, and its cost is
        // already inside the accountant's opening journal entry. Posting the acquisition would
        // double-count it — or be refused outright for landing in a closed period, and stranded
        // inside the best-effort sync job. Same rule, same reason, as `invoices.is_opening_balance`.
        if ($asset->is_opening_balance) {
            return null;
        }

        $amount = round((float) $asset->acquisition_cost, 2);
        if ($amount <= 0) {
            return null; // a zero-cost asset has no GL effect
        }

        // The property the asset was BOUGHT in — not where it is now. After a transfer (point 18)
        // the asset lives in another mall while the purchase stays in this one's books; reading
        // `asset_id` here would void and re-post the acquisition into the new mall, which is the
        // whole-history re-home the transfer act exists to replace.
        $assetId = $asset->asset_id ? $asset->propertyOn(CarbonImmutable::parse($asset->acquisition_date)) : null;
        if (! $assetId) {
            return null;
        }

        return [
            'entry_date' => $asset->acquisition_date,
            'description_en' => 'Fixed asset acquired — '.$asset->name,
            'description_ar' => 'شراء أصل ثابت — '.$asset->name,
            // The narrative is a KEY resolved at READ time (EG-36); the prose above stays as
            // the snapshot and the floor for anything that does not go through the resolver.
            'description_key' => 'fixed_asset.acquired',
            'description_data' => ['asset' => $asset->name],
            'asset_id' => $assetId,
            'lines' => [
                ['ledger_account_id' => $this->accounts->id('furniture_equipment', $assetId), 'debit' => $amount, 'credit' => 0, 'asset_id' => $assetId],
                // The credit leg: the asset's OWN bank account when it names one (point 15 —
                // `RecordsBankAccount`), else the rail's account, else the posting role — the one
                // ladder every bank-rail document resolves through, so a mall banking in two places
                // can reconcile the purchase against the statement it actually appeared on.
                ['ledger_account_id' => MoneyAccount::for($asset->bank_account_id, $asset->funded_from, $assetId, $this->accounts), 'debit' => 0, 'credit' => $amount, 'asset_id' => $assetId],
            ],
        ];
    }
}
