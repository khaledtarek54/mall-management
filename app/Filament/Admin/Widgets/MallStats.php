<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Concerns\RoleScopedWidget;
use App\Filament\Admin\Pages\ArAging;
use App\Filament\Admin\Pages\RentRoll;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\TenantRequest;
use App\Models\Unit;
use App\Services\Reports\ReportService;
use App\Support\DashboardLayout;
use App\Support\Occupancy;
use App\Support\ResourceLink;
use App\Support\TenantScope;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class MallStats extends StatsOverviewWidget
{
    use RoleScopedWidget;

    // Render order within a layout. Which layouts include this widget at all is
    // App\Support\DashboardLayout's decision, not this constant's.
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        // Property isolation: scope by visibleAssetIds() (NOT currentAssetId()) so a
        // RESTRICTED user in "All Properties" mode stays pinned to their assigned set —
        // currentAssetId() is null there and would leak the whole portfolio's KPIs.
        // null = super_admin / portfolio (genuinely platform-wide aggregates).
        $assetIds = TenantScope::visibleAssetIds();

        $unitQuery = fn () => $assetIds !== null
            ? Unit::whereIn('asset_id', $assetIds)
            : Unit::query();

        $leaseQuery = fn () => $assetIds !== null
            ? Lease::whereHas('unit', fn ($q) => $q->whereIn('asset_id', $assetIds))
            : Lease::query();

        $paymentQuery = fn () => $assetIds !== null
            ? Payment::whereHas('invoices', fn ($q) => $q->whereIn('invoices.asset_id', $assetIds))
            : Payment::query();

        $totalUnits = $unitQuery()->count();
        $occupiedUnits = $unitQuery()->where('status', 'occupied')->count();
        $vacantUnits = $unitQuery()->where('status', 'vacant')->count();
        $occupancy = $totalUnits > 0 ? round(($occupiedUnits / $totalUnits) * 100, 1) : 0;

        // Economic (GLA) occupancy — the same units weighted by leasable area, not by headcount.
        // For a mall this is the figure that tracks revenue: leasing the one 2,000 m² anchor moves
        // it far more than leasing five kiosks.
        //
        // The DEFINITION comes from `App\Support\Occupancy`, shared with `Asset::areaOccupancyRate()`;
        // only the SCOPE differs (this widget spans the operator's whole visible portfolio, the
        // model spans one property). It was written out by hand in both places, which meant the day
        // "occupied" changed, the dashboard and the property list would disagree about the mall's
        // headline number with nothing failing.
        $area = Occupancy::forUnits($unitQuery());
        $occupiedAreaSqm = $area['occupied_sqm'];
        $totalAreaSqm = $area['total_sqm'];
        $areaOccupancy = $area['pct'] ?? 0;

        $monthlyRecurring = (float) $leaseQuery()->where('status', 'active')
            ->selectRaw('SUM(base_rent_monthly + service_charge_monthly) as total')
            ->value('total');

        $now = CarbonImmutable::now();
        $startOfMonth = $now->startOfMonth();
        $startOfLastMonth = $now->subMonth()->startOfMonth();
        $endOfLastMonth = $now->subMonth()->endOfMonth();

        $collectedThisMonth = (float) $paymentQuery()->whereIn('status', Payment::RECEIVED_STATUSES)
            ->whereBetween('payment_date', [$startOfMonth, $now])
            ->sum('amount');

        $collectedLastMonth = (float) $paymentQuery()->whereIn('status', Payment::RECEIVED_STATUSES)
            ->whereBetween('payment_date', [$startOfLastMonth, $endOfLastMonth])
            ->sum('amount');

        // Receivables come from the report service, not from a private query here. The same five
        // buckets are shown on this dashboard (AR-ageing chart), on the monthly-close page and in
        // its drill-down; every copy of the bucket rules that existed disagreed with the others at
        // the boundaries. "Outstanding" is the sum of the buckets by construction, so the headline
        // and the chart underneath it cannot show two different totals.
        $buckets = app(ReportService::class)->arAgingBuckets();

        $outstandingAR = array_sum(array_column($buckets, 'total'));

        // Overdue = everything except the not-yet-due bucket.
        $overdueAR = $outstandingAR - $buckets['current']['total'];
        $overdueCount = array_sum(array_column($buckets, 'count')) - $buckets['current']['count'];

        // Tenant satisfaction (CSAT) — average close-out rating across all
        // resolved/closed requests that were rated, property-scoped.
        $ratedQuery = fn () => TenantRequest::whereNotNull('csat_rating')
            ->when($assetIds, fn ($q, $ids) => $q->whereHas('unit', fn ($u) => $u->whereIn('asset_id', $ids)));
        $ratedCount = $ratedQuery()->count();
        $avgCsat = $ratedCount > 0 ? round((float) $ratedQuery()->avg('csat_rating'), 1) : null;

        $collectionRate = $monthlyRecurring > 0
            ? round(($collectedThisMonth / $monthlyRecurring) * 100, 1)
            : 0;

        $collectedDelta = $this->percentDelta($collectedThisMonth, $collectedLastMonth);

        $occupancySeries = $this->occupancyHistorySeries(6, $assetIds);
        $collectedSeries = $this->monthlySeries(
            $paymentQuery()->whereIn('status', Payment::RECEIVED_STATUSES),
            'payment_date',
            'amount',
            6,
        );

        $occupancyColor = $occupancy >= 85 ? 'success' : ($occupancy >= 70 ? 'warning' : 'danger');
        $areaOccupancyColor = $areaOccupancy >= 85 ? 'success' : ($areaOccupancy >= 70 ? 'warning' : 'danger');

        // Occupancy, contractual rent and satisfaction are every operational role's business.
        // Collections and receivables are not: leasing and operations need to know the mall is
        // full and what it rents for, not what the tenants currently owe. See
        // DashboardLayout::MONEY_ROLES — same registry that decides the layouts, so the two
        // can't drift into disagreeing about who handles money.
        $seesMoney = DashboardLayout::seesMoney();

        // ---- Drill-downs (UX5-06) --------------------------------------
        //
        // A KPI that cannot be interrogated is a number the operator has to take on trust: the
        // dashboard is where every money role LANDS, and until now occupancy, MRR, CSAT, collections
        // and AR were all dead ends — the figure, and no way to see what it is made of.
        //
        // Each link is gated on the destination's own `canAccess()`, because this widget is shown
        // to roles with very different reach and a card that lands on a 403 is worse than a card
        // that does not link: it reads as the system being broken rather than as not-for-you.
        // `rescue()` wraps ONLY the access question, never the URL builder. A gate that cannot be
        // established reads as "no"; a URL that THROWS is a bug, and swallowing it would make every
        // drill-down vanish with nothing in the log.
        $linkTo = fn (string $screen, callable $url): ?string => rescue(
            fn (): bool => (bool) $screen::canAccess(),
            false,
            report: false,
        ) ? $url() : null;

        $stats = [
            Stat::make(__('admin.widgets.mall_stats.occupancy'), $occupancy.'%')
                ->description(__('admin.widgets.mall_stats.occupancy_desc', [
                    'occupied' => $occupiedUnits,
                    'vacant' => $vacantUnits,
                    'total' => $totalUnits,
                ]))
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color($occupancyColor)
                ->chart($occupancySeries)
                // The unit register, not the floor plan: this figure counts UNITS, and the list is
                // where the vacant ones can be filtered, sorted and acted on.
                ->url($linkTo(UnitResource::class, fn () => ResourceLink::index(UnitResource::class, tableView: 'none'))),

            // Economic occupancy sits next to the unit-count one so the two are read together:
            // a wide gap between them means the vacant space is disproportionately large (or small)
            // units. Total leasable area (m²) doubles as the denominator's context in the caption.
            Stat::make(__('admin.widgets.mall_stats.economic_occupancy'), $areaOccupancy.'%')
                ->description(__('admin.widgets.mall_stats.economic_occupancy_desc', [
                    'occupied' => number_format($occupiedAreaSqm, 0),
                    'total' => number_format($totalAreaSqm, 0),
                ]))
                ->descriptionIcon('heroicon-m-squares-2x2')
                ->color($areaOccupancyColor)
                ->url($linkTo(UnitResource::class, fn () => ResourceLink::index(UnitResource::class, tableView: 'none'))),

            // No sparkline on MRR — contractual rent is a stable number; a
            // billed-in-month sparkline would dip in the partial current month
            // and visually contradict the headline (audit F-4 / D-3).
            Stat::make(__('admin.widgets.mall_stats.monthly_revenue'), 'EGP '.number_format($monthlyRecurring, 0))
                ->description(__('admin.widgets.mall_stats.monthly_revenue_desc'))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('primary')
                // The rent roll IS this figure itemised — contractual rent per lease, which is what
                // MRR sums. Any other destination would answer a different question.
                ->url($linkTo(RentRoll::class, fn () => RentRoll::getUrl())),

            Stat::make(__('admin.widgets.mall_stats.satisfaction'), $avgCsat !== null ? $avgCsat.' / 5' : '—')
                ->description($avgCsat !== null
                    ? __('admin.widgets.mall_stats.satisfaction_desc', ['count' => $ratedCount])
                    : __('admin.widgets.mall_stats.satisfaction_none'))
                ->descriptionIcon('heroicon-m-face-smile')
                ->color($avgCsat === null ? 'gray' : ($avgCsat >= 4 ? 'success' : ($avgCsat >= 3 ? 'warning' : 'danger')))
                ->url($linkTo(TenantRequestResource::class, fn () => ResourceLink::index(TenantRequestResource::class, tableView: 'none'))),
        ];

        if ($seesMoney) {
            $stats[] = Stat::make(__('admin.widgets.mall_stats.collected_this_month'), 'EGP '.number_format($collectedThisMonth, 0))
                ->description($this->collectedDescription($collectionRate, $collectedDelta))
                ->descriptionIcon($collectedDelta >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($collectionRate >= 75 ? 'success' : ($collectionRate >= 40 ? 'warning' : 'danger'))
                ->chart($collectedSeries)
                ->url($linkTo(PaymentResource::class, fn () => ResourceLink::index(PaymentResource::class, tableView: 'none')));

            $stats[] = Stat::make(__('admin.widgets.mall_stats.outstanding_ar'), 'EGP '.number_format($outstandingAR, 0))
                ->description(__('admin.widgets.mall_stats.outstanding_ar_desc', [
                    'overdue' => number_format($overdueAR, 0),
                    'count' => $overdueCount,
                ]))
                ->descriptionIcon($overdueAR > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($overdueAR > 0 ? 'danger' : 'success')
                // AR ageing, not the invoice list: the question behind this number is always "how
                // old is it", and the ageing report is the one screen that answers that.
                ->url($linkTo(ArAging::class, fn () => ArAging::getUrl()));
        }

        return $stats;
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
    protected function occupancyHistorySeries(int $months, ?array $assetIds = null): array
    {
        $unitCountQuery = DB::table('units')->whereNull('deleted_at');
        if ($assetIds !== null) {
            $unitCountQuery->whereIn('asset_id', $assetIds);
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

            if ($assetIds !== null) {
                $occupiedQuery->join('units', 'units.id', '=', 'leases.unit_id')
                    ->whereIn('units.asset_id', $assetIds);
            }

            $occupied = $occupiedQuery->distinct('leases.unit_id')->count('leases.unit_id');

            $series[] = round(($occupied / $totalUnits) * 100, 1);
        }

        return $series;
    }
}
