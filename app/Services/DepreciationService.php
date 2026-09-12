<?php

namespace App\Services;

use App\Models\FixedAsset;
use App\Support\DepreciationProration;
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

    /**
     * What ONE month charges: the straight-line amount, the acquisition month's share of it
     * (`DepreciationProration`, point 14), never beyond what is left of the base. The one sizing
     * rule — `run()` posts it and `TaxDepreciationService::bookChargeFor()` projects a year of it,
     * so the book-vs-tax page cannot disagree with the ledger by a prorated first month.
     */
    public function chargeFor(FixedAsset $asset, CarbonImmutable $month, float $remaining): float
    {
        $fraction = DepreciationProration::firstMonthFraction(
            CarbonImmutable::parse($asset->acquisition_date),
            $month,
        );

        return round(min(round($this->monthlyAmount($asset) * $fraction, 2), $remaining), 2);
    }

    /**
     * The first month before `$month` that `run()` WOULD charge and has not — what a transfer must
     * refuse on (point 18): the OUT leg carries what is posted, so a month charged AFTER the move
     * is dimensioned to the old property and never crosses. Mirrors `run()`'s own gates month by
     * month rather than restating them: in service, not yet charged, something left of the base,
     * a non-zero charge. A cut-over asset (`is_opening_balance`) starts at its first posted month
     * — the months before are the accountant's opening figure, not a gap — or nowhere at all.
     */
    public function firstUnchargedMonthBefore(FixedAsset $asset, CarbonImmutable $month): ?CarbonImmutable
    {
        $month = $month->startOfMonth();
        $charged = $asset->depreciationEntries()->orderBy('period_month')->pluck('period_month')
            ->map(fn ($d) => CarbonImmutable::parse($d)->startOfMonth()->toDateString());

        $from = CarbonImmutable::parse($asset->acquisition_date)->startOfMonth();
        if ($asset->is_opening_balance) {
            if ($charged->isEmpty()) {
                return null;
            }
            $from = CarbonImmutable::parse($charged->first());
        }

        // A fully-depreciated asset charges nothing anywhere: `chargeFor()` clamps to what is
        // left, so no early return is needed for it (one was written, and mutation showed it
        // changed nothing).
        $remaining = round($this->depreciableBase($asset) - $this->accumulatedFor($asset), 2);

        for ($m = $from; $m->lt($month); $m = $m->addMonth()) {
            if ($charged->contains($m->toDateString())) {
                continue;
            }
            if ($this->chargeFor($asset, $m, $remaining) > 0) {
                return $m;
            }
        }

        return null;
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
        // Scoped by the property that HOLDS the asset in the month being posted, not the one it
        // sits in today (point 18): a March catch-up run scoped to the mall that held it in March
        // must reach it after an April transfer, and the receiving mall's run must not write into
        // the sending mall's ledger. The query widens to any asset that ever left one of these
        // properties; `propertyOn()` decides per asset below.
        $query = FixedAsset::active()->whereHas('asset')->select('id');
        if ($assetIds !== null) {
            $query->where(fn ($q) => $q
                ->whereIn('asset_id', $assetIds)
                ->orWhereHas('transfers', fn ($t) => $t->whereIn('from_asset_id', $assetIds)));
        }

        $query->get()->each(function ($row) use ($month, $assetIds, &$created) {
            DB::transaction(function () use ($row, $month, $assetIds, &$created) {
                /** @var FixedAsset|null $asset */
                $asset = FixedAsset::whereKey($row->id)->lockForUpdate()->first();
                if (! $asset || $asset->status !== 'active') {
                    return;
                }

                // Not yet in service for this period.
                if ($month->lt(CarbonImmutable::parse($asset->acquisition_date)->startOfMonth())) {
                    return;
                }

                // Outside the caller's authority for THIS month (see the query above).
                if ($assetIds !== null && ! in_array($asset->propertyOn($month), array_map('intval', $assetIds), true)) {
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

                // The acquisition month takes the share the company's convention says — whole,
                // or the days held over the month's days (`DepreciationProration`, point 14). Any
                // later month is whole; the last takes what is left through the clamp, so a
                // prorated first month lengthens the schedule by one partial month at the end
                // and the total stays the depreciable base.
                $amount = $this->chargeFor($asset, $month, $remaining);
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
