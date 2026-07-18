<?php

namespace App\Filament\Owner\Widgets;

use App\Models\Asset;
use App\Models\Invoice;
use App\Models\Lease;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class PortfolioStats extends BaseWidget
{
    protected function getStats(): array
    {
        $user = Auth::user();
        // Current ownership share per property [asset_id => %], tenure-aware — so a 50%
        // co-owner sees HALF the property's money, not all of it (the bug this fixes).
        $shares = $user?->currentOwnershipShares() ?? collect();
        $assetIds = $shares->keys();

        if ($assetIds->isEmpty()) {
            return [];
        }

        $assets = Asset::whereIn('id', $assetIds)
            ->withCount('units')
            ->get();

        // Physical metrics describe the property itself, so they stay portfolio-wide
        // (an owner of a mall sees the whole mall's area/occupancy); the FINANCIAL
        // metrics below are what get weighted by the ownership share.
        $totalLeasableArea = (float) $assets->sum('leasable_area_sqm');
        $assetCount = $assets->count();

        $totalUnits = 0;
        $occupiedUnits = 0;
        $mrr = 0.0;
        $outstandingAr = 0.0;
        foreach ($assets as $asset) {
            $totalUnits += (int) $asset->units_count;
            $occupiedUnits += $asset->units()->where('status', 'occupied')->count();

            $share = ((float) ($shares[$asset->id] ?? 0)) / 100;

            // MRR = sum(base_rent + service_charge) across this property's active leases,
            // times the owner's share. Per-asset (not one whereIn) so the share applies.
            $mrr += $share * (float) Lease::query()
                ->whereHas('unit', fn ($q) => $q->where('asset_id', $asset->id))
                ->where('status', 'active')
                ->sum(\DB::raw('base_rent_monthly + service_charge_monthly'));

            $outstandingAr += $share * (float) Invoice::query()
                ->whereHas('lease.unit', fn ($q) => $q->where('asset_id', $asset->id))
                ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                ->sum('balance');
        }
        $occupancyPct = $totalUnits > 0 ? round(($occupiedUnits / $totalUnits) * 100, 1) : 0;
        $mrr = round($mrr, 2);
        $outstandingAr = round($outstandingAr, 2);

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
