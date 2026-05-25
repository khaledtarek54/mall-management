<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Concerns\RoleScopedWidget;
use App\Models\Lease;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class TenantMix extends ChartWidget
{
    use RoleScopedWidget;

    protected static function allowedRoles(): array
    {
        return ['manager', 'leasing_manager', 'viewer'];
    }

    public function getHeading(): ?string
    {
        return __('admin.widgets.tenant_mix.heading');
    }

    public function getDescription(): ?string
    {
        return __('admin.widgets.tenant_mix.description');
    }

    protected static ?int $sort = 6;

    protected ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = 1;

    /**
     * Stable palette mapped to known categories; falls back per-index for unknowns.
     */
    protected array $categoryColors = [
        'retail' => '#C9A961',
        'food_beverage' => '#B85C38',
        'wellness' => '#6BA47B',
        'service' => '#5B7B9A',
        'kiosk' => '#D7C49E',
        'office' => '#8C8478',
        'storage' => '#4A4A4A',
    ];

    protected function getData(): array
    {
        $assetId = \App\Support\TenantScope::currentAssetId();

        $query = Lease::query()
            ->where('leases.status', 'active')
            ->join('units', 'units.id', '=', 'leases.unit_id')
            ->selectRaw('units.category as category, COUNT(*) as cnt')
            ->groupBy('units.category')
            ->orderByDesc('cnt');

        if ($assetId) {
            $query->where('units.asset_id', $assetId);
        }

        $counts = $query->pluck('cnt', 'category');

        $labels = [];
        $data = [];
        $colors = [];

        foreach ($counts as $category => $cnt) {
            $labels[] = __("admin.enums.category.{$category}");
            $data[] = (int) $cnt;
            $colors[] = $this->categoryColors[$category] ?? '#A0A0A0';
        }

        return [
            'datasets' => [
                [
                    'label' => __('admin.widgets.tenant_mix.label'),
                    'data' => $data,
                    'backgroundColor' => $colors,
                    'borderColor' => 'rgba(26,26,26,0.6)',
                    'borderWidth' => 2,
                    'hoverOffset' => 8,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): RawJs
    {
        $unitsLabel = __('admin.widgets.tenant_mix.units');

        return RawJs::make(<<<JS
        {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '55%',
            layout: { padding: 8 },
            plugins: {
                legend: {
                    position: 'bottom',
                    align: 'center',
                    labels: {
                        usePointStyle: true,
                        boxWidth: 8,
                        padding: 10,
                        color: 'rgba(140,132,120,0.95)'
                    }
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
                            const total = ctx.dataset.data.reduce(function(a,b){ return a+b; }, 0);
                            const v = ctx.parsed;
                            const pct = total > 0 ? ((v / total) * 100).toFixed(1) : 0;
                            return ctx.label + ': ' + v + ' {$unitsLabel} (' + pct + '%)';
                        }
                    }
                }
            }
        }
        JS);
    }
}
