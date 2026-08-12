<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Concerns\RoleScopedWidget;
use App\Services\Reports\ReportService;
use App\Support\AgingBuckets;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class ArAging extends ChartWidget
{
    use RoleScopedWidget;

    public function getHeading(): ?string
    {
        return __('admin.widgets.ar_aging.heading');
    }

    public function getDescription(): ?string
    {
        return __('admin.widgets.ar_aging.description');
    }

    protected static ?int $sort = 3;

    // Full width. It used to be half, paired with TenantMix — but the two are no longer in any
    // one role's layout together (money roles get the ageing, leasing/marketing get the mix), so
    // half-width just left an empty column beside it. A five-bucket bar chart also reads better
    // wide than tall.
    // Half-width on desktop so the two charts a role reads together sit side by side; the
    // dashboard declares 2 columns (Dashboard::getColumns()) and every widget used to be
    // 'full', which made the grid decorative and gave a manager a ~2,900px scroll.
    // Stacks below `md` — a chart squeezed into half a phone screen is unreadable.
    protected int|string|array $columnSpan = ['default' => 'full', 'md' => 1];

    protected ?string $maxHeight = '320px';

    protected function getData(): array
    {
        // One bucket definition for the whole system: ReportService::arAgingBuckets() is what
        // the monthly-close cards and the AR-ageing drill-down use. This widget used to carry
        // its own copy — comparing a midnight `due_date` against a `now()` that has a TIME on
        // it, so every invoice sitting exactly on a boundary (due today, or 30/60/90 days late)
        // was pushed one bucket too far and the dashboard chart disagreed with the report.
        $buckets = app(ReportService::class)->arAgingBuckets();

        return [
            'datasets' => [
                [
                    'label' => __('admin.widgets.ar_aging.label'),
                    'data' => array_map(fn ($b) => $b['total'], array_values($buckets)),
                    'backgroundColor' => ['#3B8C5A', '#D8A53A', '#E37B36', '#C8453A', '#7A1F1F'],
                    'borderWidth' => 0,
                    'borderRadius' => 6,
                    'borderSkipped' => false,
                    'counts' => array_map(fn ($b) => $b['count'], array_values($buckets)),
                ],
            ],
            'labels' => [
                __('admin.widgets.ar_aging.current'),
                ...array_values(AgingBuckets::overdueLabels()),
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): RawJs
    {
        $invoiceLabel = __('admin.widgets.ar_aging.invoices');

        return RawJs::make(<<<JS
        {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(26,26,26,0.95)',
                    titleColor: '#F5F1EA',
                    bodyColor: '#F5F1EA',
                    borderColor: 'rgba(201,169,97,0.4)',
                    borderWidth: 1,
                    padding: 10,
                    callbacks: {
                        label: function(ctx) {
                            const v = ctx.parsed.y;
                            const counts = ctx.dataset.counts || [];
                            const cnt = counts[ctx.dataIndex] || 0;
                            return [
                                'EGP ' + new Intl.NumberFormat('en').format(v),
                                cnt + ' {$invoiceLabel}'
                            ];
                        }
                    }
                }
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
                }
            }
        }
        JS);
    }
}
