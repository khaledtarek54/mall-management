<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\ArAging;
use App\Filament\Admin\Pages\Reports;
use App\Services\Reports\ReportService;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Reactive;

/**
 * The month's headline numbers, plus the AR ageing buckets, for the monthly
 * close page.
 *
 * These were a hand-built grid of <x-filament::section> blocks with inline font
 * sizes and hard-coded hex colours. As native Stats they pick up the panel's
 * theme (including each property's own primary colour) and dark mode, and each
 * ageing bucket keeps its click-through into the AR drill-down.
 *
 * Belongs to the Reports page, which drives it with a period picker — it is NOT part of any
 * dashboard layout (see `DashboardLayout::NOT_ON_DASHBOARD`). That used to be a claim in this
 * docblock and nothing more: Filament auto-discovers every widget in this directory, this one
 * declared no gate at all, and so the property's invoicing, collections rate, outstanding AR and
 * all five ageing buckets were published to every role on the panel — HR and marketing included.
 * `canView()` now ties it to the same `reports.view` permission as the page it belongs to.
 */
class MonthlyCloseStats extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return Auth::user()?->can('reports.view') ?? false;
    }

    /**
     * Y-m of the period being closed; injected by the Reports page.
     *
     * `#[Reactive]` because Livewire only mounts a child once: without it the cards froze
     * at the month the page first loaded while the revenue table below followed the picker,
     * so the two halves of the page described different months.
     */
    #[Reactive]
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
                // Hand the drill-down the SAME ageing date these buckets were computed at.
                // Without it the page re-aged at "now" and listed a different set of
                // invoices than the total on the card the operator just clicked.
                ->url(ArAging::getUrl([
                    'bucket' => $key,
                    'asOf' => $report['ar_aging_as_of'],
                ]));
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
