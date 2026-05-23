<?php

namespace App\Filament\Owner\Widgets;

use App\Models\Asset;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Scopes\CurrentOperatorScope;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class PortfolioStats extends BaseWidget
{
    protected function getStats(): array
    {
        $user = Auth::user();
        $assetIds = $user?->ownedAssets()->withoutGlobalScopes()->pluck('assets.id') ?? collect();

        if ($assetIds->isEmpty()) {
            return [];
        }

        // Owned assets, bypass operator scoping
        $assets = Asset::withoutGlobalScopes([CurrentOperatorScope::class])
            ->whereIn('id', $assetIds)
            ->withCount('units')
            ->get();

        $totalLeasableArea = (float) $assets->sum('leasable_area_sqm');
        $assetCount = $assets->count();

        // Aggregate occupancy across the portfolio
        $totalUnits = 0;
        $occupiedUnits = 0;
        foreach ($assets as $asset) {
            $totalUnits += (int) $asset->units_count;
            $occupiedUnits += $asset->units()->where('status', 'occupied')->count();
        }
        $occupancyPct = $totalUnits > 0 ? round(($occupiedUnits / $totalUnits) * 100, 1) : 0;

        // MRR = sum(base_rent + service_charge) across active leases on units of owned assets
        $mrr = (float) Lease::query()
            ->whereHas('unit', fn ($q) => $q->whereIn('asset_id', $assetIds))
            ->where('status', 'active')
            ->sum(\DB::raw('base_rent_monthly + service_charge_monthly'));

        // Outstanding AR
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
            Stat::make(__('admin.widgets.portfolio.mrr'), 'EGP ' . number_format($mrr, 0))
                ->description(__('admin.widgets.portfolio.mrr_desc'))
                ->color('primary'),
            Stat::make(__('admin.widgets.portfolio.outstanding'), 'EGP ' . number_format($outstandingAr, 0))
                ->description(__('admin.widgets.portfolio.outstanding_desc'))
                ->color($outstandingAr > 0 ? 'warning' : 'success'),
        ];
    }
}
