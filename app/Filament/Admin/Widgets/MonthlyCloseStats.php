<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\ArAging;
use App\Filament\Admin\Pages\Reports;
use App\Services\Reports\ReportService;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The month's headline numbers, plus the AR ageing buckets, for the monthly
 * close page.
 *
 * These were a hand-built grid of <x-filament::section> blocks with inline font
 * sizes and hard-coded hex colours. As native Stats they pick up the panel's
 * theme (including each property's own primary colour) and dark mode, and each
 * ageing bucket keeps its click-through into the AR drill-down.
 *
 * NOT registered on the dashboard — it is driven by the period the Reports page
 * is showing, which is passed in as a widget property.
 */
class MonthlyCloseStats extends StatsOverviewWidget
{
    /** Y-m of the period being closed; injected by the Reports page. */
    public ?string $period = null;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $report = app(ReportService::class)->monthlyClose($this->resolvePeriod());

        $stats = [
            Stat::make(__('admin.reports.invoices_issued'), number_format($report['invoices']['count']))
                ->description('EGP '.number_format($report['invoices']['total'], 2))
                ->icon('heroicon-o-document-text'),

            Stat::make(__('admin.reports.payments_captured'), number_format($report['payments']['count']))
                ->description('EGP '.number_format($report['payments']['total'], 2))
                ->color('success')
                ->icon('heroicon-o-banknotes'),

            Stat::make(__('admin.reports.collections_rate'), number_format($report['collections_rate'], 1).'%')
                ->description(__('admin.reports.of_invoiced'))
                // 80% is the line between a normal month and one that needs chasing.
                ->color($report['collections_rate'] >= 80 ? 'success' : 'warning')
                ->icon('heroicon-o-chart-bar'),

            Stat::make(__('admin.reports.outstanding_ar'), 'EGP '.number_format($report['outstanding_total'], 0))
                ->description(__('admin.reports.as_of_close'))
                ->color('danger')
                ->icon('heroicon-o-exclamation-triangle'),
        ];

        // Each ageing bucket stays a link into the drill-down — that
        // click-through is how a collections run starts.
        $bucketColours = [
            'current' => 'success',
            'd_1_30' => 'warning',
            'd_31_60' => 'warning',
            'd_61_90' => 'danger',
            'd_90_plus' => 'danger',
        ];

        foreach ($report['ar_aging'] as $key => $row) {
            $stats[] = Stat::make(
                __("admin.widgets.ar_aging.{$key}"),
                'EGP '.number_format($row['total'], 0),
            )
                ->description($row['count'].' '.__('admin.widgets.ar_aging.invoices'))
                ->color($bucketColours[$key] ?? 'gray')
                ->url(ArAging::getUrl(['bucket' => $key]));
        }

        return $stats;
    }

    private function resolvePeriod(): CarbonImmutable
    {
        // Shared with the page so the cards and the revenue table can never
        // describe two different months.
        return Reports::parsePeriod($this->period);
    }
}
