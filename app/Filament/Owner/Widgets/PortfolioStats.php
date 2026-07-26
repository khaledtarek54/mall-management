<?php

namespace App\Filament\Owner\Widgets;

use App\Models\Asset;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Unit;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class PortfolioStats extends BaseWidget
{
    protected function getStats(): array
    {
        $user = Auth::user();
        // Properties the owner CURRENTLY owns (tenure-aware — a sold-off stake drops out).
        // The operator model is one owner per mall who receives 100% of its money, so the
        // owner sees the FULL property figures (no ownership-% split — that stays available
        // via currentOwnershipShares() for a future multi-owner build, but isn't applied).
        $assetIds = $user?->currentOwnedAssets()->pluck('assets.id') ?? collect();

        if ($assetIds->isEmpty()) {
            return [];
        }

        $assets = Asset::whereIn('id', $assetIds)
            ->withCount('units')
            ->get();

        $totalLeasableArea = (float) $assets->sum('leasable_area_sqm');
        $assetCount = $assets->count();

        $totalUnits = 0;
        $occupiedUnits = 0;
        foreach ($assets as $asset) {
            $totalUnits += (int) $asset->units_count;
            $occupiedUnits += $asset->units()->where('status', 'occupied')->count();
        }
        $occupancyPct = $totalUnits > 0 ? round(($occupiedUnits / $totalUnits) * 100, 1) : 0;

        // Economic (GLA) occupancy across the owned malls — occupied leasable area ÷ total, summed
        // bottom-up from the units so it can never exceed 100% just because a declared GLA and the
        // unit areas disagree. This is the occupancy figure that tracks the owner's rent roll.
        $occupiedAreaSqm = (float) Unit::query()
            ->whereIn('asset_id', $assetIds)
            ->where('status', 'occupied')
            ->sum('area_sqm');
        $totalUnitAreaSqm = (float) Unit::query()
            ->whereIn('asset_id', $assetIds)
            ->sum('area_sqm');
        $areaOccupancyPct = $totalUnitAreaSqm > 0
            ? round(($occupiedAreaSqm / $totalUnitAreaSqm) * 100, 1)
            : 0;

        // MRR = sum(base_rent + service_charge) across active leases on the owned malls.
        $mrr = (float) Lease::query()
            ->whereHas('unit', fn ($q) => $q->whereIn('asset_id', $assetIds))
            ->where('status', 'active')
            ->sum(\DB::raw('base_rent_monthly + service_charge_monthly'));

        $outstandingAr = (float) Invoice::query()
            ->whereHas('lease.unit', fn ($q) => $q->whereIn('asset_id', $assetIds))
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->sum('balance');

        return [
            Stat::make(__('admin.widgets.portfolio.assets'), $assetCount)
                ->description(__('admin.widgets.portfolio.assets_desc', [
                    'sqm' => number_format($totalLeasableArea, 0),
                ]))
                ->color('gray'),
            Stat::make(__('admin.widgets.portfolio.occupancy'), $occupancyPct . '%')
                ->description(__('admin.widgets.portfolio.occupancy_desc', [
                    'occupied' => $occupiedUnits,
                    'total' => $totalUnits,
                ]))
                ->color($occupancyPct >= 70 ? 'success' : ($occupancyPct >= 50 ? 'warning' : 'danger')),
            Stat::make(__('admin.widgets.portfolio.economic_occupancy'), $areaOccupancyPct . '%')
                ->description(__('admin.widgets.portfolio.economic_occupancy_desc', [
                    'occupied' => number_format($occupiedAreaSqm, 0),
                    'total' => number_format($totalUnitAreaSqm, 0),
                ]))
                ->color($areaOccupancyPct >= 70 ? 'success' : ($areaOccupancyPct >= 50 ? 'warning' : 'danger')),
            Stat::make(__('admin.widgets.portfolio.mrr'), 'EGP ' . number_format($mrr, 0))
                ->description(__('admin.widgets.portfolio.mrr_desc'))
                ->color('primary'),
            Stat::make(__('admin.widgets.portfolio.outstanding'), 'EGP ' . number_format($outstandingAr, 0))
                ->description(__('admin.widgets.portfolio.outstanding_desc'))
                ->color($outstandingAr > 0 ? 'warning' : 'success'),
        ];
    }
}
