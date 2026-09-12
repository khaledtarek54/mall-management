<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\FixedAsset;
use App\Models\FixedAssetTransfer;
use App\Models\FixedAssetTransferLeg;
use App\Services\DepreciationService;
use App\Support\AssignedAssets;
use App\Support\PostingDate;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Moves a fixed asset from one property to another as a dated, reasoned ACT (meeting 2026-09-02,
 * point 18) — SAP's intra-company transfer (ABUMN), Yardi's asset transfer.
 *
 * What it does, in one transaction under a lock on the asset: records the `FixedAssetTransfer`,
 * writes its two GL legs (cost and accumulated depreciation OUT of the old property and IN to the
 * new, dated on the transfer date — {@see FixedAssetTransferLegJournalizer}), stamps the reason
 * into the audit trail, and moves the asset's `asset_id`. History stays where it was:
 * `FixedAsset::propertyOn()` keeps the acquisition and every posted charge dimensioned to the
 * property that held the asset then, and the months after follow the asset.
 *
 * What it refuses, each in the reader's words: a disposed asset; the property it is already in; a
 * property the actor does not hold; a date in a closed period, in the future, or in the
 * acquisition month (a wrong property at registration is a CORRECTION on the asset while nothing
 * has depreciated — the free edit, not a transfer); a date in or before a month whose depreciation
 * is already posted (that month's charge belongs to the receiving property, and re-dimensioning a
 * posted charge is the restatement this act exists to avoid — date it from the next month); a date
 * before an earlier transfer (history is chronological); and a tag the receiving property already
 * uses (tags are unique per property — re-tag the other asset first).
 */
class TransferFixedAssetService
{
    public function __construct(private DepreciationService $depreciation) {}

    /**
     * @param  array{to_asset_id:mixed, transferred_on:mixed, reason:mixed}  $data
     */
    public function transfer(FixedAsset $asset, array $data): FixedAssetTransfer
    {
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new DomainException(__('admin.fixed_assets.errors.transfer_reason_required'));
        }

        // The transfer date is BOTH legs' entry date: refused before anything is written when its
        // period is closed (the disposal's own rule), and it cannot lie ahead of today — the act
        // records something that happened.
        $on = CarbonImmutable::instance(PostingDate::assertNotFuture($data['transferred_on'] ?? null, 'transferred_on'));

        return DB::transaction(function () use ($asset, $data, $on, $reason): FixedAssetTransfer {
            $locked = FixedAsset::whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'active') {
                throw new DomainException(__('admin.fixed_assets.errors.transfer_disposed'));
            }

            $from = (int) $locked->asset_id;
            $to = (int) ($data['to_asset_id'] ?? 0);

            if ($to === $from) {
                throw new DomainException(__('admin.fixed_assets.errors.transfer_same_property'));
            }

            // A real mall the actor holds — never the "All Properties" pseudo-asset (a view mode,
            // not a place), and never a mall outside their assignment: the picker narrows to the
            // same set, and this is the check that stands when the id arrives some other way.
            $destination = Asset::query()->where('code', '!=', Asset::ALL_PROPERTIES_CODE)->find($to);
            $held = AssignedAssets::idsForCurrentUser();
            if ($destination === null || ($held !== null && ! in_array($to, $held, true))) {
                throw new DomainException(__('admin.fixed_assets.errors.transfer_property_not_held'));
            }

            $month = $on->startOfMonth();
            $acquiredMonth = CarbonImmutable::parse($locked->acquisition_date)->startOfMonth();
            if ($month->lte($acquiredMonth)) {
                throw new DomainException(__('admin.fixed_assets.errors.transfer_in_acquisition_month', [
                    'month' => $acquiredMonth->locale(app()->getLocale())->isoFormat('MMMM YYYY'),
                    'from' => $acquiredMonth->addMonth()->locale(app()->getLocale())->isoFormat('D MMMM YYYY'),
                ]));
            }

            // The OUT leg carries what has been POSTED before the transfer month, so every month
            // before it must BE posted, or a catch-up run after the move dimensions that month's
            // charge to the old property (correctly — `propertyOn()`) where no leg ever carries it
            // across: the old mall's Accumulated stays credited for an asset it no longer holds,
            // the new mall's is short by the same, for ever, with the trial balance still footing
            // (the review measured it, 2026-09-12). Two refusals, one for each side of the month.
            $lastCharged = $locked->depreciationEntries()->max('period_month');
            if ($lastCharged !== null && CarbonImmutable::parse($lastCharged)->startOfMonth()->gte($month)) {
                throw new DomainException(__('admin.fixed_assets.errors.transfer_month_already_charged', [
                    'month' => CarbonImmutable::parse($lastCharged)->locale(app()->getLocale())->isoFormat('MMMM YYYY'),
                    'from' => CarbonImmutable::parse($lastCharged)->startOfMonth()->addMonth()->locale(app()->getLocale())->isoFormat('D MMMM YYYY'),
                ]));
            }

            $uncharged = $this->depreciation->firstUnchargedMonthBefore($locked, $month);
            if ($uncharged !== null) {
                throw new DomainException(__('admin.fixed_assets.errors.transfer_month_uncharged', [
                    'month' => $uncharged->locale(app()->getLocale())->isoFormat('MMMM YYYY'),
                ]));
            }

            $previous = $locked->transfers()->reorder()->orderByDesc('transferred_on')->orderByDesc('id')->first();
            if ($previous !== null && $previous->transferred_on->gt($on)) {
                throw new DomainException(__('admin.fixed_assets.errors.transfer_out_of_order', [
                    'date' => $previous->transferred_on->locale(app()->getLocale())->isoFormat('D MMMM YYYY'),
                ]));
            }

            // Tags are unique per PROPERTY (the identity the importer keys on), so the receiving
            // mall may already have this number on something else.
            $clash = FixedAsset::withTrashed()->where('asset_id', $to)->where('tag', $locked->tag)->whereKeyNot($locked->getKey())->exists();
            if ($clash) {
                throw new DomainException(__('admin.fixed_assets.errors.transfer_tag_taken', [
                    'tag' => $locked->tag,
                    'property' => $destination->name,
                ]));
            }

            $cost = round((float) $locked->acquisition_cost, 2);
            $accumulated = round(min($cost, $locked->accumulatedDepreciationBefore($month)), 2);

            $transfer = $locked->transfers()->create([
                'from_asset_id' => $from,
                'to_asset_id' => $to,
                'transferred_on' => $on->toDateString(),
                'cost' => $cost,
                'accumulated_depreciation' => $accumulated,
                'reason' => $reason,
                'created_by_user_id' => auth()->id(),
            ]);

            foreach ([FixedAssetTransferLeg::OUT => $from, FixedAssetTransferLeg::IN => $to] as $direction => $assetId) {
                $transfer->legs()->create([
                    'fixed_asset_id' => $locked->getKey(),
                    'asset_id' => $assetId,
                    'direction' => $direction,
                    'transferred_on' => $on->toDateString(),
                    'cost' => $cost,
                    'accumulated_depreciation' => $accumulated,
                ]);
            }

            // The reason, where it cannot be edited afterwards — the `ReversalReason` rule: the row
            // above is immutable too, but the trail is what an auditor reads first, and it names
            // the actor. Data, never prose: the description is a KEY.
            activity('fixed_asset')
                ->performedOn($locked)
                ->event('transferred')
                ->withProperties([
                    'reason' => $reason,
                    'from_asset_id' => $from,
                    'to_asset_id' => $to,
                    'transferred_on' => $on->toDateString(),
                ])
                ->log('fixed_asset.transferred');

            // The one write of `asset_id` a committed asset accepts — recognised by the transfer
            // row just written (`FixedAsset::restatementPermittedBecause()`). The model's own
            // `updated` hook then bumps the depreciation entries so the sweep re-reads them, and
            // `propertyOn()` answers the same property for every month already charged.
            $locked->asset_id = $to;
            $locked->save();

            return $transfer;
        });
    }
}
