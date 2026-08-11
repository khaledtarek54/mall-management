<?php

namespace App\Services;

use App\Models\FixedAsset;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Straight-line depreciation for the fixed-asset register (module 23).
 *
 * Monthly charge = (acquisition_cost − salvage_value) ÷ useful_life_months, spread
 * from the acquisition month until the depreciable base is exhausted. Accumulated
 * depreciation is DERIVED as SUM(depreciation_entries.amount) — never cached.
 */
class DepreciationService
{
    /** The depreciable base: cost less salvage value (never negative). */
    public function depreciableBase(FixedAsset $asset): float
    {
        return round(max(0, (float) $asset->acquisition_cost - (float) $asset->salvage_value), 2);
    }

    /** Straight-line monthly charge (0 if useful life is unset). */
    public function monthlyAmount(FixedAsset $asset): float
    {
        $life = (int) $asset->useful_life_months;

        return $life > 0 ? round($this->depreciableBase($asset) / $life, 2) : 0.0;
    }

    /** Accumulated depreciation to date = SUM of this asset's entries. */
    public function accumulatedFor(FixedAsset $asset): float
    {
        // Delegates: `opening_accumulated_depreciation` counts too, and the rule lives in one
        // place because the disposal journalizer needs the same answer (FixedAsset).
        return $asset->accumulatedDepreciation();
    }

    /** Net book value = cost − accumulated depreciation. */
    public function netBookValue(FixedAsset $asset): float
    {
        return round((float) $asset->acquisition_cost - $this->accumulatedFor($asset), 2);
    }

    /**
     * Refuse a re-cost that would put the depreciable base BELOW what has already been
     * depreciated. The `min(monthly, remaining)` clamp in run() only protects the FORWARD
     * charge; nothing stopped an operator editing `acquisition_cost` (or `salvage_value`)
     * downward after charges had posted. Drop cost 120,000 → 30,000 on an asset that has
     * already accumulated 60,000 and: accumulated (60,000) now exceeds the new base (30,000),
     * so NBV is −30,000, `remaining` is negative, and depreciation stops forever — while the
     * ledger carries −30,000 of net fixed assets. Doc rules 2 and 4 ("accumulated tops out at
     * cost − salvage, NEVER beyond") are about exactly this (gap-analysis F-86).
     *
     * The proposed cost/salvage are checked as a pair (the base is cost − salvage). Called
     * server-side from EditFixedAsset — the form cannot know accumulated depreciation.
     *
     * @throws \DomainException when the new base would be below posted accumulated depreciation
     */
    public function assertRecostValid(FixedAsset $asset, float $newCost, float $newSalvage): void
    {
        $newBase = round(max(0, $newCost - $newSalvage), 2);
        $accumulated = $this->accumulatedFor($asset);

        if ($newBase < $accumulated) {
            throw new \DomainException(__('admin.fixed_assets.errors.recost_below_accumulated', [
                'base' => number_format($newBase, 2),
                'accumulated' => number_format($accumulated, 2),
            ]));
        }
    }

    /**
     * Post depreciation for a period (default: current month) across ACTIVE fixed
     * assets. Idempotent + lock-safe (one entry per asset+month; each row locked and
     * re-checked inside its own transaction). The LAST charge is clamped so accumulated
     * never exceeds the depreciable base. Skips assets not yet acquired by the period,
     * or already fully depreciated. Returns the number of entries created.
     *
     * @param  array<int>|null  $assetIds  Restrict to these properties (asset_id). Null =
     *                                     portfolio-wide (the scheduled monthly run); the
     *                                     admin "post this month" button passes the user's
     *                                     visible-property set so a scoped user never posts
     *                                     outside their authority.
     */
    public function run(?CarbonInterface $period = null, ?array $assetIds = null): int
    {
        $month = ($period ? CarbonImmutable::instance($period) : CarbonImmutable::now())->startOfMonth();
        $created = 0;

        // whereHas('asset') excludes fixed assets whose PROPERTY was soft-deleted — a
        // soft-delete doesn't fire the FK cascade, so without this the portfolio run
        // would keep charging (and posting GL for) a deleted mall forever.
        $query = FixedAsset::active()->whereHas('asset')->select('id');
        if ($assetIds !== null) {
            $query->whereIn('asset_id', $assetIds);
        }

        $query->get()->each(function ($row) use ($month, &$created) {
            DB::transaction(function () use ($row, $month, &$created) {
                /** @var FixedAsset|null $asset */
                $asset = FixedAsset::whereKey($row->id)->lockForUpdate()->first();
                if (! $asset || $asset->status !== 'active') {
                    return;
                }

                // Not yet in service for this period.
                if ($month->lt(CarbonImmutable::parse($asset->acquisition_date)->startOfMonth())) {
                    return;
                }

                // Already charged this month (idempotent). whereDate so the comparison
                // ignores any time component on the stored date.
                if ($asset->depreciationEntries()->whereDate('period_month', $month->toDateString())->exists()) {
                    return;
                }

                $remaining = round($this->depreciableBase($asset) - $this->accumulatedFor($asset), 2);
                if ($remaining <= 0) {
                    return; // fully depreciated
                }

                $amount = round(min($this->monthlyAmount($asset), $remaining), 2);
                if ($amount <= 0) {
                    return;
                }

                $asset->depreciationEntries()->create([
                    'period_month' => $month->toDateString(),
                    'amount' => $amount,
                    'created_by_user_id' => auth()->id(),
                ]);
                $created++;
            });
        });

        return $created;
    }
}
