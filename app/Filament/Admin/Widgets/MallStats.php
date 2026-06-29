<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Concerns\RoleScopedWidget;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class MallStats extends StatsOverviewWidget
{
    use RoleScopedWidget;

    // Headline KPIs — everyone with admin access sees these.
    protected static function allowedRoles(): array
    {
        return ['manager', 'viewer', 'leasing', 'operations'];
    }

    // Dashboard order: SetupGuide(-1) → ActionRequired(0) → MallStats(1)
    // → LeasingPipeline(2) → ExpiringLeases(3) → TopTenants(4) →
    // ArAging(5) + TenantMix(6) [paired half-width charts] →
    // EtaCompliance(7) → MonthlyRevenueTrend(8) → RecentPayments(9) →
    // OpenMaintenanceRequests(10) → EnergyConsumptionTrend(11).
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $assetId = \App\Support\TenantScope::currentAssetId();

        // Tenant context: scope all queries to current property. "All
        // Properties" view returns currentAssetId() === null, so the
        // helpers fall back to platform-wide aggregates.
        $unitQuery = fn () => $assetId
            ? \App\Models\Unit::where('asset_id', $assetId)
            : \App\Models\Unit::query();

        $leaseQuery = fn () => $assetId
            ? Lease::whereHas('unit', fn ($q) => $q->where('asset_id', $assetId))
            : Lease::query();

        $invoiceQuery = fn () => $assetId
            ? Invoice::whereHas('lease.unit', fn ($q) => $q->where('asset_id', $assetId))
            : Invoice::query();

        $paymentQuery = fn () => $assetId
            ? Payment::whereHas('invoices.lease.unit', fn ($q) => $q->where('asset_id', $assetId))
            : Payment::query();

        $totalUnits = $unitQuery()->count();
        $occupiedUnits = $unitQuery()->where('status', 'occupied')->count();
        $vacantUnits = $unitQuery()->where('status', 'vacant')->count();
        $occupancy = $totalUnits > 0 ? round(($occupiedUnits / $totalUnits) * 100, 1) : 0;

        $monthlyRecurring = (float) $leaseQuery()->where('status', 'active')
            ->selectRaw('SUM(base_rent_monthly + service_charge_monthly) as total')
            ->value('total');

        $now = CarbonImmutable::now();
        $startOfMonth = $now->startOfMonth();
        $startOfLastMonth = $now->subMonth()->startOfMonth();
        $endOfLastMonth = $now->subMonth()->endOfMonth();

        $collectedThisMonth = (float) $paymentQuery()->where('status', 'captured')
            ->whereBetween('payment_date', [$startOfMonth, $now])
            ->sum('amount');

        $collectedLastMonth = (float) $paymentQuery()->where('status', 'captured')
            ->whereBetween('payment_date', [$startOfLastMonth, $endOfLastMonth])
            ->sum('amount');

        $outstandingAR = (float) $invoiceQuery()->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->sum('balance');

        $overdueAR = (float) $invoiceQuery()->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->where('balance', '>', 0)
            ->where('due_date', '<', now())
            ->sum('balance');

        $overdueCount = $invoiceQuery()->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->where('balance', '>', 0)
            ->where('due_date', '<', now())
            ->count();

        // Tenant satisfaction (CSAT) — average close-out rating across all
        // resolved/closed requests that were rated, property-scoped.
        $ratedQuery = fn () => \App\Models\TenantRequest::whereNotNull('csat_rating')
            ->when($assetId, fn ($q) => $q->whereHas('unit', fn ($u) => $u->where('asset_id', $assetId)));
        $ratedCount = $ratedQuery()->count();
        $avgCsat = $ratedCount > 0 ? round((float) $ratedQuery()->avg('csat_rating'), 1) : null;

        $collectionRate = $monthlyRecurring > 0
            ? round(($collectedThisMonth / $monthlyRecurring) * 100, 1)
            : 0;

        $collectedDelta = $this->percentDelta($collectedThisMonth, $collectedLastMonth);

        $occupancySeries = $this->occupancyHistorySeries(6, $assetId);
        $billedSeries = $this->monthlySeries(
            $invoiceQuery()->whereNotIn('status', ['cancelled', 'credited']),
            'period_start',
            'total',
            6,
        );
        $collectedSeries = $this->monthlySeries(
            $paymentQuery()->where('status', 'captured'),
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

            // No sparkline on MRR — contractual rent is a stable number; a
            // billed-in-month sparkline would dip in the partial current month
            // and visually contradict the headline (audit F-4 / D-3).
            Stat::make(__('admin.widgets.mall_stats.monthly_revenue'), 'EGP '.number_format($monthlyRecurring, 0))
                ->description(__('admin.widgets.mall_stats.monthly_revenue_desc'))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('primary'),

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

            Stat::make(__('admin.widgets.mall_stats.satisfaction'), $avgCsat !== null ? $avgCsat.' / 5' : '—')
                ->description($avgCsat !== null
                    ? __('admin.widgets.mall_stats.satisfaction_desc', ['count' => $ratedCount])
                    : __('admin.widgets.mall_stats.satisfaction_none'))
                ->descriptionIcon('heroicon-m-face-smile')
                ->color($avgCsat === null ? 'gray' : ($avgCsat >= 4 ? 'success' : ($avgCsat >= 3 ? 'warning' : 'danger'))),
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
        // Both zero or no prior data → no meaningful delta. Returning a
        // hard-coded 100 % (the previous behavior) misled fresh installs
        // into showing "↑ 100 % vs last month" when there was no baseline
        // to compare against (audit F-5 / D-4).
        if ($previous <= 0) {
            return null;
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

        $driver = DB::connection()->getDriverName();
        $monthExpr = match ($driver) {
            'sqlite' => "strftime('%Y-%m', {$dateColumn})",
            'pgsql' => "to_char({$dateColumn}, 'YYYY-MM')",
            default => "DATE_FORMAT({$dateColumn}, '%Y-%m')",
        };

        $rows = (clone $query)
            ->whereBetween($dateColumn, [$start, $end])
            ->selectRaw("{$monthExpr} as ym, SUM({$sumColumn}) as total")
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
    protected function occupancyHistorySeries(int $months, ?int $assetId = null): array
    {
        $unitCountQuery = DB::table('units')->whereNull('deleted_at');
        if ($assetId) {
            $unitCountQuery->where('asset_id', $assetId);
        }
        $totalUnits = max(1, $unitCountQuery->count());

        $now = CarbonImmutable::now();
        $series = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthEnd = $now->subMonths($i)->endOfMonth();
            $occupiedQuery = DB::table('leases')
                ->whereNull('leases.deleted_at')
                ->whereNotIn('leases.status', ['cancelled', 'draft'])
                ->where('leases.commencement_date', '<=', $monthEnd)
                ->where(function ($q) use ($monthEnd) {
                    $q->whereNull('leases.expiry_date')->orWhere('leases.expiry_date', '>=', $monthEnd);
                });

            if ($assetId) {
                $occupiedQuery->join('units', 'units.id', '=', 'leases.unit_id')
                    ->where('units.asset_id', $assetId);
            }

            $occupied = $occupiedQuery->distinct('leases.unit_id')->count('leases.unit_id');

            $series[] = round(($occupied / $totalUnits) * 100, 1);
        }

        return $series;
    }
}
