<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Concerns\RoleScopedWidget;
use App\Support\TenantScope;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class EnergyConsumptionTrend extends ChartWidget
{
    use RoleScopedWidget;

    protected static function widgetModule(): ?string
    {
        return 'utility_meters';
    }

    public function getHeading(): ?string
    {
        return __('admin.widgets.energy_consumption.heading');
    }

    public function getDescription(): ?string
    {
        return __('admin.widgets.energy_consumption.description');
    }

    protected static ?int $sort = 11;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    protected function getData(): array
    {
        $end = CarbonImmutable::now()->endOfMonth();
        $start = CarbonImmutable::now()->startOfMonth()->subMonths(11);
        // Property isolation: visibleAssetIds() keeps a restricted user pinned to their
        // set in All-Properties mode (currentAssetId() is null there → portfolio leak).
        $assetIds = TenantScope::visibleAssetIds();

        $monthExpr = match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', reading_date)",
            'pgsql' => "to_char(reading_date, 'YYYY-MM')",
            default => "DATE_FORMAT(reading_date, '%Y-%m')",
        };

        $query = DB::table('meter_readings')
            ->join('utility_meters', 'utility_meters.id', '=', 'meter_readings.utility_meter_id')
            // Raw join bypasses UtilityMeter's SoftDeletes global scope — exclude decommissioned
            // meters by hand, or a soft-deleted meter's consumption rolls into the trend forever
            // (and the module doc tells operators to soft-delete a meter to drop it from the chart).
            ->whereNull('utility_meters.deleted_at')
            ->selectRaw("{$monthExpr} as ym, utility_meters.type as type, SUM(consumption) as total")
            ->whereBetween('reading_date', [$start, $end])
            ->groupBy('ym', 'type');

        if ($assetIds !== null) {
            $query->whereIn('utility_meters.asset_id', $assetIds);
        }

        // Sum consumption per (month, meter type) — gives us 3 series across 12 months.
        $rows = $query->get();

        $labels = [];
        $current = $start;
        while ($current <= $end) {
            $labels[$current->format('Y-m')] = $current->locale(app()->getLocale())->isoFormat('MMM YYYY');
            $current = $current->addMonth();
        }

        $palette = [
            'electric' => '#F59E0B',
            'water' => '#3B82F6',
            'gas' => '#DC2626',
        ];

        $datasets = [];
        foreach (['electric', 'water', 'gas'] as $type) {
            $values = array_fill_keys(array_keys($labels), 0);
            foreach ($rows->where('type', $type) as $row) {
                $values[$row->ym] = (float) $row->total;
            }

            $datasets[] = [
                'label' => __("admin.enums.meter_type.{$type}"),
                'data' => array_values($values),
                'backgroundColor' => $palette[$type],
                'borderColor' => $palette[$type],
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => array_values($labels),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => false],
                'y' => ['stacked' => false, 'beginAtZero' => true],
            ],
            'plugins' => [
                'legend' => ['position' => 'top'],
            ],
        ];
    }
}
