<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\FixedAssetTransferLeg;
use App\Services\Accounting\AccountResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * One side of a fixed-asset transfer between properties → GL (meeting 2026-09-02, point 18).
 *
 * The OUT leg, in the property the asset LEFT:
 *
 *   Dr Accumulated Depreciation (what had accumulated)
 *   Dr Inter-property Clearing   (the net book value)
 *   Cr Furniture & Equipment     (the cost)
 *
 * The IN leg, in the property it JOINED, is the mirror. SAP's ABUMN posts exactly this pair on the
 * transfer date and leaves history where it was; two entries rather than one with mixed lines
 * because every statement here scopes on the ENTRY's property, so a single entry would put one
 * mall's half of the move into the other's balance sheet. Portfolio-wide the clearing account nets
 * to zero; per property it is what one mall handed another.
 *
 * A fully-depreciated asset has no net book value and raises no clearing line; an asset with no
 * accumulated depreciation raises none for it. Tie-out-safe: touches neither AR nor AP.
 */
class FixedAssetTransferLegJournalizer implements Journalizer
{
    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var FixedAssetTransferLeg $leg */
        $leg = $source;

        // A trashed parent (the asset reversed as recorded in error) voids its transfers with it.
        $asset = $leg->fixedAsset;
        if (! $asset || $asset->trashed()) {
            return null;
        }

        $assetId = (int) $leg->asset_id;
        $cost = round((float) $leg->cost, 2);
        $accumulated = round(min($cost, (float) $leg->accumulated_depreciation), 2);
        $nbv = round($cost - $accumulated, 2);

        if ($cost <= 0 || ! $assetId) {
            return null;
        }

        $out = $leg->direction === FixedAssetTransferLeg::OUT;
        $line = fn (string $role, float $amount, bool $debit): array => [
            'ledger_account_id' => $this->accounts->id($role, $assetId),
            'debit' => $debit ? $amount : 0,
            'credit' => $debit ? 0 : $amount,
            'asset_id' => $assetId,
        ];

        $lines = [$line('furniture_equipment', $cost, ! $out)];

        if ($accumulated > 0) {
            $lines[] = $line('accumulated_depreciation', $accumulated, $out);
        }

        if ($nbv > 0) {
            $lines[] = $line('inter_property_clearing', $nbv, $out);
        }

        $counterparty = $leg->transfer?->{$out ? 'toAsset' : 'fromAsset'}?->name;

        return [
            'entry_date' => $leg->transferred_on,
            'description_en' => ($out ? 'Fixed asset transferred out — ' : 'Fixed asset transferred in — ').$asset->name,
            'description_ar' => ($out ? 'تحويل أصل ثابت إلى عقار آخر — ' : 'استلام أصل ثابت من عقار آخر — ').$asset->name,
            // The narrative is a KEY resolved at READ time (EG-36); the prose above is the snapshot.
            'description_key' => $out ? 'fixed_asset.transferred_out' : 'fixed_asset.transferred_in',
            'description_data' => ['asset' => $asset->name, 'property' => $counterparty],
            'asset_id' => $assetId,
            'lines' => $lines,
        ];
    }
}
