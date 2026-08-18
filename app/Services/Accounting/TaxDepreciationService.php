<?php

namespace App\Services\Accounting;

use App\Models\FixedAsset;
use App\Services\DepreciationService;
use App\Support\TaxDepreciation;
use App\Support\TenantScope;
use Carbon\CarbonImmutable;

/**
 * The Egyptian income-tax depreciation schedule (Law 91/2005, Art. 25) — a SCHEDULE, not a ledger.
 *
 * The accounting book depreciates straight-line over a chosen useful life and posts to the GL. Tax
 * is a different calculation on the same assets: statutory rates, and for most of them a **pooled
 * diminishing-value** base where an asset loses its individual identity — additions join the pool,
 * the rate applies to the whole written-down balance, and no single asset ever reaches zero.
 *
 * Nothing here posts. Egypt files single-book: the statutory accounts stay on the accounting basis
 * and the tax figure is a computation attached to the return, so a second set of journal entries
 * would be a second set of balances to keep reconciled for no filing benefit. What an accountant
 * needs from this is the schedule and the DIFFERENCE from the book figure — the temporary
 * difference — and both come out of one pass.
 *
 * **It is computed from history every time, never accumulated into a column.** Rolling the pools
 * forward year by year from acquisition is the only way the closing balance can be trusted: a
 * stored written-down value would drift the first time an asset was re-costed or disposed of, and
 * a tax basis that quietly disagrees with the register is worse than none, because it gets filed.
 */
class TaxDepreciationService
{
    public function __construct(private DepreciationService $book) {}

    /**
     * The full schedule for one tax year.
     *
     * @return array{
     *     year: int,
     *     pools: array<int, array{pool:string, rate:float, pooled:bool, opening:float, additions:float,
     *                             disposals:float, base:float, depreciation:float, closing:float, assets:int}>,
     *     tax_total: float, book_total: float, difference: float
     * }
     */
    public function schedule(int $year, ?array $assetIds = null): array
    {
        $assetIds ??= TenantScope::visibleAssetIds();

        $assets = FixedAsset::query()
            ->when($assetIds !== null, fn ($q) => $q->whereIn('asset_id', $assetIds))
            ->get();

        $pools = [];
        $taxTotal = 0.0;

        foreach (TaxDepreciation::pools() as $pool) {
            if ($pool === TaxDepreciation::NONE) {
                continue;   // land and the like: in the register, never in the schedule
            }

            $inPool = $assets->filter(fn (FixedAsset $a) => ($a->tax_pool ?: TaxDepreciation::default()) === $pool);

            if ($inPool->isEmpty()) {
                continue;
            }

            $row = TaxDepreciation::isPooled($pool)
                ? $this->pooledRow($pool, $inPool, $year)
                : $this->straightLineRow($pool, $inPool, $year);

            $taxTotal += $row['depreciation'];
            $pools[] = $row;
        }

        $bookTotal = $this->bookChargeFor($assets, $year);

        return [
            'year' => $year,
            'pools' => $pools,
            'tax_total' => round($taxTotal, 2),
            'book_total' => round($bookTotal, 2),
            // Positive = tax relieves MORE than the books this year, so taxable profit is lower
            // than accounting profit — a temporary difference that reverses over the asset's life.
            'difference' => round($taxTotal - $bookTotal, 2),
        ];
    }

    /**
     * A pooled class, rolled forward from the first acquisition to the year asked for.
     *
     * Replayed rather than stored — see the class docblock. The cost is one pass over the assets
     * per year of history, which is nothing against the cost of filing a wrong number.
     */
    private function pooledRow(string $pool, $assets, int $year): array
    {
        $rate = TaxDepreciation::rateFor($pool) / 100;
        $first = $assets->min(fn (FixedAsset $a) => (int) CarbonImmutable::parse($a->acquisition_date)->year);

        $wdv = 0.0;
        $additions = $disposals = $depreciation = 0.0;

        for ($y = $first; $y <= $year; $y++) {
            $opening = $wdv;

            $additions = round((float) $assets
                ->filter(fn (FixedAsset $a) => (int) CarbonImmutable::parse($a->acquisition_date)->year === $y)
                ->sum('acquisition_cost'), 2);

            // A disposal leaves the pool at COST — the law removes the asset's own cost from the
            // base, not its book value, and using NBV here would leave a permanent residue in the
            // pool that depreciates forever.
            $disposals = round((float) $assets
                ->filter(fn (FixedAsset $a) => $a->disposed_on
                    && (int) CarbonImmutable::parse($a->disposed_on)->year === $y)
                ->sum('acquisition_cost'), 2);

            $base = max(0.0, round($opening + $additions - $disposals, 2));
            $depreciation = round($base * $rate, 2);
            $wdv = round($base - $depreciation, 2);

            if ($y === $year) {
                return [
                    'pool' => $pool,
                    'rate' => TaxDepreciation::rateFor($pool),
                    'pooled' => true,
                    'opening' => $opening,
                    'additions' => $additions,
                    'disposals' => $disposals,
                    'base' => $base,
                    'depreciation' => $depreciation,
                    'closing' => $wdv,
                    'assets' => $assets->count(),
                ];
            }
        }

        // The year asked for is before anything was acquired.
        return [
            'pool' => $pool, 'rate' => TaxDepreciation::rateFor($pool), 'pooled' => true,
            'opening' => 0.0, 'additions' => 0.0, 'disposals' => 0.0, 'base' => 0.0,
            'depreciation' => 0.0, 'closing' => 0.0, 'assets' => $assets->count(),
        ];
    }

    /**
     * Buildings and intangibles: a percentage of COST, per asset, straight-line.
     *
     * Capped so total tax relief never exceeds cost — twenty years at 5% exhausts a building, and
     * the twenty-first must charge nothing rather than carry on into negative written-down value.
     */
    private function straightLineRow(string $pool, $assets, int $year): array
    {
        $rate = TaxDepreciation::rateFor($pool) / 100;
        $cost = $depreciation = $claimedToDate = 0.0;

        foreach ($assets as $asset) {
            $acquired = (int) CarbonImmutable::parse($asset->acquisition_date)->year;
            $disposed = $asset->disposed_on ? (int) CarbonImmutable::parse($asset->disposed_on)->year : null;

            $cost += (float) $asset->acquisition_cost;

            if ($acquired > $year || ($disposed !== null && $disposed <= $year)) {
                continue;
            }

            $annual = round((float) $asset->acquisition_cost * $rate, 2);
            $yearsClaimed = $year - $acquired;                       // full years before this one
            $alreadyClaimed = round($annual * $yearsClaimed, 2);
            $remaining = max(0.0, round((float) $asset->acquisition_cost - $alreadyClaimed, 2));

            $depreciation += min($annual, $remaining);
            $claimedToDate += min($alreadyClaimed, (float) $asset->acquisition_cost);
        }

        $depreciation = round($depreciation, 2);
        $opening = round(max(0.0, $cost - $claimedToDate), 2);

        return [
            'pool' => $pool,
            'rate' => TaxDepreciation::rateFor($pool),
            'pooled' => false,
            'opening' => $opening,
            'additions' => round((float) $assets
                ->filter(fn (FixedAsset $a) => (int) CarbonImmutable::parse($a->acquisition_date)->year === $year)
                ->sum('acquisition_cost'), 2),
            'disposals' => round((float) $assets
                ->filter(fn (FixedAsset $a) => $a->disposed_on
                    && (int) CarbonImmutable::parse($a->disposed_on)->year === $year)
                ->sum('acquisition_cost'), 2),
            'base' => round($cost, 2),
            'depreciation' => $depreciation,
            'closing' => round(max(0.0, $opening - $depreciation), 2),
            'assets' => $assets->count(),
        ];
    }

    /**
     * What the ACCOUNTING book charged the same assets that year — the other half of the
     * difference an accountant is looking for.
     */
    private function bookChargeFor($assets, int $year): float
    {
        $total = 0.0;

        foreach ($assets as $asset) {
            $monthly = $this->book->monthlyAmount($asset);

            if ($monthly <= 0) {
                continue;
            }

            $start = CarbonImmutable::parse($asset->acquisition_date);
            $end = $asset->disposed_on ? CarbonImmutable::parse($asset->disposed_on) : null;

            for ($m = 1; $m <= 12; $m++) {
                $month = CarbonImmutable::create($year, $m, 1);

                if ($month->lt($start->startOfMonth())) {
                    continue;
                }
                if ($end && $month->gt($end->startOfMonth())) {
                    break;
                }

                // Never beyond the depreciable base — the same clamp the posting run applies.
                $monthsElapsed = $start->startOfMonth()->diffInMonths($month) + 1;
                if ($monthsElapsed > (int) $asset->useful_life_months) {
                    break;
                }

                $total += $monthly;
            }
        }

        return round($total, 2);
    }
}
