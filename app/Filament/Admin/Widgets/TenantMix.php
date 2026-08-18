<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Concerns\RoleScopedWidget;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Models\Lease;
use App\Support\ResourceLink;
use App\Support\TenantScope;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class TenantMix extends ChartWidget
{
    use RoleScopedWidget;

    public function getHeading(): ?string
    {
        return __('admin.widgets.tenant_mix.heading');
    }

    /**
     * The description carries the way IN, exactly as the AR-ageing chart's does.
     *
     * The chart answers "what is the mix?" and then stopped: a reader who saw one category
     * dominating had no way to ask WHICH tenants those were, and had to know the Units list existed
     * and carried a category filter.
     *
     * One link to that list, not one per slice. A per-SLICE handler could only be JavaScript on the
     * chart canvas, and nothing in this environment can exercise that in a real browser — the same
     * reason `ArAging` stops at one link. The Units list already has the category `SelectFilter`,
     * so landing there is landing on the whole breakdown rather than one wedge of it.
     */
    public function getDescription(): ?Htmlable
    {
        $url = ResourceLink::index(UnitResource::class);

        return new HtmlString(
            e(__('admin.widgets.tenant_mix.description'))
            .' <a href="'.e($url).'" class="fi-link fi-size-sm" style="text-decoration:underline;">'
            .e(__('admin.widgets.tenant_mix.drilldown')).'</a>'
        );
    }

    protected static ?int $sort = 4;

    // Half-width on desktop so the two charts a role reads together sit side by side; the
    // dashboard declares 2 columns (Dashboard::getColumns()) and every widget used to be
    // 'full', which made the grid decorative and gave a manager a ~2,900px scroll.
    // Stacks below `md` — a chart squeezed into half a phone screen is unreadable.
    protected int|string|array $columnSpan = ['default' => 'full', 'md' => 1];

    protected ?string $maxHeight = '320px';

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
        // Property isolation: visibleAssetIds() keeps a restricted user pinned to their
        // set in All-Properties mode (currentAssetId() is null there → portfolio leak).
        $assetIds = TenantScope::visibleAssetIds();

        $query = Lease::query()
            ->where('leases.status', 'active')
            ->join('units', 'units.id', '=', 'leases.unit_id')
            ->selectRaw('units.category as category, COUNT(*) as cnt')
            ->groupBy('units.category')
            ->orderByDesc('cnt');

        if ($assetIds !== null) {
            $query->whereIn('units.asset_id', $assetIds);
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
