<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Concerns\RoleScopedWidget;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Models\Lease;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Leasing-funnel view: where is each lease in its lifecycle, and what
 * EGP value sits at each stage? Surfaces the work the leasing team
 * has to push forward — drafts that need approval, signed leases
 * that haven't started billing, expirations needing renewal.
 */
class LeasingPipeline extends StatsOverviewWidget
{
    use RoleScopedWidget;

    protected static function allowedRoles(): array
    {
        return ['manager', 'leasing_manager'];
    }

    protected static ?int $sort = 4;

    protected function getStats(): array
    {
        $byStatus = Lease::query()
            ->selectRaw('status, COUNT(*) as count, SUM(base_rent_monthly + service_charge_monthly) as monthly_value')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $get = function (string $status) use ($byStatus) {
            $row = $byStatus->get($status);
            return [
                'count' => (int) ($row->count ?? 0),
                'value' => (float) ($row->monthly_value ?? 0),
            ];
        };

        $draft = $get('draft');
        $pending = $get('pending_approval');
        $active = $get('active');
        $renewed = $get('renewed');

        return [
            Stat::make(__('admin.widgets.pipeline.draft'), number_format($draft['count']))
                ->description(__('admin.widgets.pipeline.value', ['amount' => number_format($draft['value'], 0)]))
                ->descriptionIcon('heroicon-m-document')
                ->color('gray')
                ->url(LeaseResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'draft']]])),

            Stat::make(__('admin.widgets.pipeline.pending_approval'), number_format($pending['count']))
                ->description(__('admin.widgets.pipeline.value', ['amount' => number_format($pending['value'], 0)]))
                ->descriptionIcon('heroicon-m-clock')
                ->color($pending['count'] > 0 ? 'warning' : 'gray')
                ->url(LeaseResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'pending_approval']]])),

            Stat::make(__('admin.widgets.pipeline.active'), number_format($active['count']))
                ->description(__('admin.widgets.pipeline.value', ['amount' => number_format($active['value'], 0)]))
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success')
                ->url(LeaseResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'active']]])),

            Stat::make(__('admin.widgets.pipeline.renewed'), number_format($renewed['count']))
                ->description(__('admin.widgets.pipeline.renewed_desc'))
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('info')
                ->url(LeaseResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'renewed']]])),
        ];
    }
}
