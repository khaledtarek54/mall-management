<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Concerns\RoleScopedWidget;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\TenantScope;
use Carbon\CarbonImmutable;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class MonthlyRevenueTrend extends ChartWidget
{
    use RoleScopedWidget;

    public function getHeading(): ?string
    {
        return __('admin.widgets.monthly_revenue_trend.heading');
    }

    public function getDescription(): ?string
    {
        return __('admin.widgets.monthly_revenue_trend.description');
    }

    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '320px';

    protected function getData(): array
    {
        $end = CarbonImmutable::now()->endOfMonth();
        $start = CarbonImmutable::now()->startOfMonth()->subMonths(11);
        $currentMonthKey = CarbonImmutable::now()->format('Y-m');
        // Property isolation: visibleAssetIds() keeps a restricted user pinned to their
        // set in All-Properties mode (currentAssetId() is null there → portfolio leak).
        $assetIds = TenantScope::visibleAssetIds();
        $monthExpr = fn (string $col): string => match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', {$col})",
            'pgsql' => "to_char({$col}, 'YYYY-MM')",
            default => "DATE_FORMAT({$col}, '%Y-%m')",
        };

        $invoiceBase = $assetIds !== null
            ? Invoice::whereHas('lease.unit', fn ($q) => $q->whereIn('asset_id', $assetIds))
            : Invoice::query();

        $paymentBase = $assetIds !== null
            ? Payment::whereHas('invoices.lease.unit', fn ($q) => $q->whereIn('asset_id', $assetIds))
            : Payment::query();

        $billed = (clone $invoiceBase)
            ->selectRaw(''.$monthExpr('period_start').' as ym, SUM(total) as amount')
            ->whereBetween('period_start', [$start, $end])
            ->whereNotIn('status', ['cancelled', 'credited'])
            ->groupBy('ym')
            ->pluck('amount', 'ym');

        $collected = (clone $paymentBase)
            ->selectRaw(''.$monthExpr('payment_date').' as ym, SUM(amount) as amount')
            ->whereBetween('payment_date', [$start, $end])
            ->whereIn('status', Payment::RECEIVED_STATUSES)
            ->groupBy('ym')
            ->pluck('amount', 'ym');

        // Collection rate is computed per invoice month (payments allocated back
        // to the month their invoice was issued in), not per cash-flow month —
        // otherwise payments-on-old-AR distort the ratio.
        $paidPerMonthQuery = DB::table('invoice_payment')
            ->join('invoices', 'invoices.id', '=', 'invoice_payment.invoice_id')
            // Count only RECEIVED allocations — mirror the `$collected` bar + Invoice::recomputeTotals.
            // Without this the collection-rate line counts an abandoned Paymob `initiated` allocation
            // (the pivot carries the full balance pre-capture) and refunded allocations (the pivot is
            // kept as history), so the rate would overstate collection and disagree with its own bar.
            ->join('payments', 'payments.id', '=', 'invoice_payment.payment_id')
            ->whereIn('payments.status', Payment::RECEIVED_STATUSES)
            ->whereBetween('invoices.period_start', [$start, $end])
            ->whereNotIn('invoices.status', ['cancelled', 'credited']);

        if ($assetIds !== null) {
            $paidPerMonthQuery->join('leases', 'leases.id', '=', 'invoices.lease_id')
                ->join('units', 'units.id', '=', 'leases.unit_id')
                ->whereIn('units.asset_id', $assetIds);
        }

        $paidPerInvoiceMonth = $paidPerMonthQuery
            ->selectRaw(''.$monthExpr('invoices.period_start').' as ym, SUM(invoice_payment.allocated_amount) as amount')
            ->groupBy('ym')
            ->pluck('amount', 'ym');

        $labels = [];
        $billedSeries = [];
        $collectedSeries = [];
        $rateSeries = [];

        for ($i = 0; $i < 12; $i++) {
            $month = $start->addMonths($i);
            $key = $month->format('Y-m');
            $labels[] = $month->locale(app()->getLocale())->isoFormat('MMM YYYY');

            $b = (float) ($billed[$key] ?? 0);
            $c = (float) ($collected[$key] ?? 0);
            $paidForBilled = (float) ($paidPerInvoiceMonth[$key] ?? 0);

            $billedSeries[] = $b;
            $collectedSeries[] = $c;

            // Skip the current (partial) month — its invoices haven't had a
            // chance to be collected yet and would drag the line to ~0.
            if ($key === $currentMonthKey || $b <= 0) {
                $rateSeries[] = null;
            } else {
                $rateSeries[] = round(($paidForBilled / $b) * 100, 1);
            }
        }

        return [
            'datasets' => [
                [
                    'label' => __('admin.widgets.monthly_revenue_trend.billed'),
                    'data' => $billedSeries,
                    'backgroundColor' => 'rgba(140, 132, 120, 0.55)',
                    'borderColor' => 'rgba(140, 132, 120, 1)',
                    'borderWidth' => 0,
                    'borderRadius' => 6,
                    'borderSkipped' => false,
                    'yAxisID' => 'y',
                    'order' => 2,
                ],
                [
                    'label' => __('admin.widgets.monthly_revenue_trend.collected'),
                    'data' => $collectedSeries,
                    'backgroundColor' => 'rgba(201, 169, 97, 0.9)',
                    'borderColor' => 'rgba(201, 169, 97, 1)',
                    'borderWidth' => 0,
                    'borderRadius' => 6,
                    'borderSkipped' => false,
                    'yAxisID' => 'y',
                    'order' => 1,
                ],
                [
                    'type' => 'line',
                    'label' => __('admin.widgets.monthly_revenue_trend.collection_rate'),
                    'data' => $rateSeries,
                    'borderColor' => '#B85C38',
                    'backgroundColor' => '#B85C38',
                    'borderWidth' => 2,
                    'pointRadius' => 3,
                    'pointHoverRadius' => 5,
                    'tension' => 0.35,
                    'yAxisID' => 'rate',
                    'order' => 0,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
        {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    align: 'end',
                    labels: { usePointStyle: true, boxWidth: 8, padding: 14 },
                },
                tooltip: {
                    backgroundColor: 'rgba(26,26,26,0.95)',
                    titleColor: '#F5F1EA',
                    bodyColor: '#F5F1EA',
                    borderColor: 'rgba(201,169,97,0.4)',
                    borderWidth: 1,
                    padding: 10,
                    callbacks: {
                        label: function(ctx) {
                            const label = ctx.dataset.label || '';
                            const v = ctx.parsed.y;
                            if (ctx.dataset.yAxisID === 'rate') {
                                return label + ': ' + v.toFixed(1) + '%';
                            }
                            return label + ': EGP ' + new Intl.NumberFormat('en').format(v);
                        }
                    }
                },
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: 'rgba(140,132,120,0.9)' },
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(140,132,120,0.12)' },
                    ticks: {
                        color: 'rgba(140,132,120,0.9)',
                        callback: function(v) {
                            return 'EGP ' + new Intl.NumberFormat('en', { notation: 'compact' }).format(v);
                        }
                    }
                },
                rate: {
                    position: 'right',
                    beginAtZero: true,
                    max: 110,
                    grid: { display: false },
                    ticks: {
                        color: '#B85C38',
                        callback: function(v) { return v + '%'; }
                    }
                }
            }
        }
        JS);
    }
}
