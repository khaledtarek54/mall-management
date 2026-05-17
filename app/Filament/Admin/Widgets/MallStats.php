<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Asset;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class MallStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $asset = Asset::first();

        $occupancy = $asset?->occupancyRate() ?? 0;
        $totalUnits = $asset?->units()->count() ?? 0;
        $occupiedUnits = $asset?->occupiedUnitsCount() ?? 0;
        $vacantUnits = $asset?->vacantUnitsCount() ?? 0;

        $monthlyRecurring = (float) Lease::where('status', 'active')
            ->selectRaw('SUM(base_rent_monthly + service_charge_monthly) as total')
            ->value('total');

        $now = CarbonImmutable::now();
        $startOfMonth = $now->startOfMonth();
        $startOfLastMonth = $now->subMonth()->startOfMonth();
        $endOfLastMonth = $now->subMonth()->endOfMonth();

        $collectedThisMonth = (float) Payment::where('status', 'captured')
            ->whereBetween('payment_date', [$startOfMonth, $now])
            ->sum('amount');

        $collectedLastMonth = (float) Payment::where('status', 'captured')
            ->whereBetween('payment_date', [$startOfLastMonth, $endOfLastMonth])
            ->sum('amount');

        $outstandingAR = (float) Invoice::whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->sum('balance');

        $overdueAR = (float) Invoice::where('balance', '>', 0)
            ->where('due_date', '<', now())
            ->sum('balance');

        $overdueCount = Invoice::where('balance', '>', 0)
            ->where('due_date', '<', now())
            ->count();

        $collectionRate = $monthlyRecurring > 0
            ? round(($collectedThisMonth / $monthlyRecurring) * 100, 1)
            : 0;

        $collectedDelta = $this->percentDelta($collectedThisMonth, $collectedLastMonth);

        $occupancySeries = $this->occupancyHistorySeries(6);
        $billedSeries = $this->monthlySeries(
            Invoice::query()->whereNotIn('status', ['cancelled', 'credited']),
            'period_start',
            'total',
            6,
        );
        $collectedSeries = $this->monthlySeries(
            Payment::query()->where('status', 'captured'),
            'payment_date',
            'amount',
            6,
        );

        $occupancyColor = $occupancy >= 85 ? 'success' : ($occupancy >= 70 ? 'warning' : 'danger');

        return [
            Stat::make(__('admin.widgets.mall_stats.occupancy'), $occupancy.'%')
                ->description(__('admin.widgets.mall_stats.occupancy_desc', [
                    'occupied' => $occupiedUnits,
                    'vacant' => $vacantUnits,
                    'total' => $totalUnits,
                ]))
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color($occupancyColor)
                ->chart($occupancySeries),

            Stat::make(__('admin.widgets.mall_stats.monthly_revenue'), 'EGP '.number_format($monthlyRecurring, 0))
                ->description(__('admin.widgets.mall_stats.monthly_revenue_desc'))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('primary')
                ->chart($billedSeries),

            Stat::make(__('admin.widgets.mall_stats.collected_this_month'), 'EGP '.number_format($collectedThisMonth, 0))
                ->description($this->collectedDescription($collectionRate, $collectedDelta))
                ->descriptionIcon($collectedDelta >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($collectionRate >= 75 ? 'success' : ($collectionRate >= 40 ? 'warning' : 'danger'))
                ->chart($collectedSeries),

            Stat::make(__('admin.widgets.mall_stats.outstanding_ar'), 'EGP '.number_format($outstandingAR, 0))
                ->description(__('admin.widgets.mall_stats.outstanding_ar_desc', [
                    'overdue' => number_format($overdueAR, 0),
                    'count' => $overdueCount,
                ]))
                ->descriptionIcon($overdueAR > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($overdueAR > 0 ? 'danger' : 'success'),
        ];
    }

    protected function collectedDescription(float $collectionRate, ?float $momDelta): string
    {
        $rate = __('admin.widgets.mall_stats.collected_pct_desc', ['pct' => $collectionRate]);

        if ($momDelta === null) {
            return $rate;
        }

        $direction = $momDelta >= 0 ? '↑' : '↓';
        $signed = $direction.' '.number_format(abs($momDelta), 1).'%';

        return $rate.' · '.__('admin.widgets.mall_stats.vs_last_month', ['delta' => $signed]);
    }

    protected function percentDelta(float $current, float $previous): ?float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Build a 6-month series of summed values, oldest → newest, with zero-fill.
     */
    protected function monthlySeries($query, string $dateColumn, string $sumColumn, int $months): array
    {
        $end = CarbonImmutable::now()->endOfMonth();
        $start = $end->subMonths($months - 1)->startOfMonth();

        $rows = (clone $query)
            ->whereBetween($dateColumn, [$start, $end])
            ->selectRaw("DATE_FORMAT({$dateColumn}, '%Y-%m') as ym, SUM({$sumColumn}) as total")
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $series = [];
        for ($i = 0; $i < $months; $i++) {
            $key = $start->addMonths($i)->format('Y-m');
            $series[] = (float) ($rows[$key] ?? 0);
        }

        return $series;
    }

    /**
     * Approximate historical occupancy ratios for the last N months,
     * based on leases whose [commencement_date .. expiry_date] cover each month-end.
     */
    protected function occupancyHistorySeries(int $months): array
    {
        $totalUnits = max(1, DB::table('units')->whereNull('deleted_at')->count());
        $now = CarbonImmutable::now();
        $series = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthEnd = $now->subMonths($i)->endOfMonth();
            $occupied = DB::table('leases')
                ->whereNull('deleted_at')
                ->whereNotIn('status', ['cancelled', 'draft'])
                ->where('commencement_date', '<=', $monthEnd)
                ->where(function ($q) use ($monthEnd) {
                    $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $monthEnd);
                })
                ->distinct('unit_id')
                ->count('unit_id');

            $series[] = round(($occupied / $totalUnits) * 100, 1);
        }

        return $series;
    }
}
