<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Concerns\RoleScopedWidget;
use App\Models\Invoice;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class ArAging extends ChartWidget
{
    use RoleScopedWidget;

    protected static function allowedRoles(): array
    {
        return ['manager', 'viewer'];
    }

    public function getHeading(): ?string
    {
        return __('admin.widgets.ar_aging.heading');
    }

    public function getDescription(): ?string
    {
        return __('admin.widgets.ar_aging.description');
    }

    protected static ?int $sort = 5;

    // protected ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = 1;

    protected function getData(): array
    {
        $base = fn () => \App\Support\TenantScope::applyTo(Invoice::query(), 'lease.unit');

        $buckets = [
            'current' => [
                'amount' => (float) $base()->where('balance', '>', 0)
                    ->where('due_date', '>=', now())
                    ->sum('balance'),
                'count' => $base()->where('balance', '>', 0)
                    ->where('due_date', '>=', now())
                    ->count(),
            ],
            '1_30' => [
                'amount' => (float) $base()->where('balance', '>', 0)
                    ->where('due_date', '<', now())
                    ->where('due_date', '>=', now()->subDays(30))
                    ->sum('balance'),
                'count' => $base()->where('balance', '>', 0)
                    ->where('due_date', '<', now())
                    ->where('due_date', '>=', now()->subDays(30))
                    ->count(),
            ],
            '31_60' => [
                'amount' => (float) $base()->where('balance', '>', 0)
                    ->where('due_date', '<', now()->subDays(30))
                    ->where('due_date', '>=', now()->subDays(60))
                    ->sum('balance'),
                'count' => $base()->where('balance', '>', 0)
                    ->where('due_date', '<', now()->subDays(30))
                    ->where('due_date', '>=', now()->subDays(60))
                    ->count(),
            ],
            '61_90' => [
                'amount' => (float) $base()->where('balance', '>', 0)
                    ->where('due_date', '<', now()->subDays(60))
                    ->where('due_date', '>=', now()->subDays(90))
                    ->sum('balance'),
                'count' => $base()->where('balance', '>', 0)
                    ->where('due_date', '<', now()->subDays(60))
                    ->where('due_date', '>=', now()->subDays(90))
                    ->count(),
            ],
            '90_plus' => [
                'amount' => (float) $base()->where('balance', '>', 0)
                    ->where('due_date', '<', now()->subDays(90))
                    ->sum('balance'),
                'count' => $base()->where('balance', '>', 0)
                    ->where('due_date', '<', now()->subDays(90))
                    ->count(),
            ],
        ];

        return [
            'datasets' => [
                [
                    'label' => __('admin.widgets.ar_aging.label'),
                    'data' => array_map(fn ($b) => $b['amount'], array_values($buckets)),
                    'backgroundColor' => ['#3B8C5A', '#D8A53A', '#E37B36', '#C8453A', '#7A1F1F'],
                    'borderWidth' => 0,
                    'borderRadius' => 6,
                    'borderSkipped' => false,
                    'counts' => array_map(fn ($b) => $b['count'], array_values($buckets)),
                ],
            ],
            'labels' => [
                __('admin.widgets.ar_aging.current'),
                __('admin.widgets.ar_aging.d_1_30'),
                __('admin.widgets.ar_aging.d_31_60'),
                __('admin.widgets.ar_aging.d_61_90'),
                __('admin.widgets.ar_aging.d_90_plus'),
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
