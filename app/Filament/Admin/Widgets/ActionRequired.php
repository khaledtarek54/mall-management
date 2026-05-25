<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Concerns\RoleScopedWidget;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\Unit;
use Carbon\Carbon;
use Filament\Widgets\Widget;

class ActionRequired extends Widget
{
    use RoleScopedWidget;

    // ActionRequired is the inbox — every operational role sees it.
    protected static function allowedRoles(): array
    {
        return ['manager', 'leasing_manager', 'maintenance_manager'];
    }

    protected string $view = 'filament.admin.widgets.action-required';

    protected static ?int $sort = 0;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function getViewData(): array
    {
        $now = Carbon::now();

        $overdueInvoicesQuery = Invoice::where('balance', '>', 0)->where('due_date', '<', $now);
        $overdueCount = (clone $overdueInvoicesQuery)->count();
        $overdueAmount = (clone $overdueInvoicesQuery)->sum('balance');

        $expiringCriticalCount = Lease::where('status', 'active')
            ->whereBetween('expiry_date', [$now, (clone $now)->addDays(30)])
            ->count();

        $expiringSoonCount = Lease::where('status', 'active')
            ->whereBetween('expiry_date', [(clone $now)->addDays(31), (clone $now)->addDays(90)])
            ->count();

        $vacantCount = Unit::where('status', 'vacant')->count();

        $urgentMaintenanceCount = MaintenanceRequest::whereIn('status', MaintenanceRequest::OPEN_STATUSES)
            ->where('priority', 'urgent')
            ->count();

        $slaBreachedCount = MaintenanceRequest::whereIn('status', MaintenanceRequest::OPEN_STATUSES)
            ->whereNotNull('target_resolution_at')
            ->where('target_resolution_at', '<', $now)
            ->count();

        $monthStart = (clone $now)->startOfMonth();
        $monthEnd = (clone $now)->endOfMonth();
        $unbilledLeasesCount = Lease::where('status', 'active')
            ->whereDoesntHave('invoices', function ($q) use ($monthStart, $monthEnd) {
                $q->whereBetween('period_start', [$monthStart, $monthEnd]);
            })
            ->count();

        $items = [];

        if ($urgentMaintenanceCount > 0) {
            $items[] = [
                'key' => 'urgent_maintenance',
                'icon' => 'heroicon-o-wrench-screwdriver',
                'color' => 'danger',
                'title' => trans_choice('admin.widgets.action_required.urgent_maintenance', $urgentMaintenanceCount, ['count' => $urgentMaintenanceCount]),
                'body' => __('admin.widgets.action_required.urgent_maintenance_body'),
                'url' => MaintenanceRequestResource::getUrl('index', ['tableFilters' => ['priority' => ['value' => 'urgent']]]),
            ];
        }

        if ($slaBreachedCount > 0) {
            $items[] = [
                'key' => 'sla_breached',
                'icon' => 'heroicon-o-clock',
                'color' => 'danger',
                'title' => trans_choice('admin.widgets.action_required.sla_breached', $slaBreachedCount, ['count' => $slaBreachedCount]),
                'body' => __('admin.widgets.action_required.sla_breached_body'),
                'url' => MaintenanceRequestResource::getUrl('index', ['tableFilters' => ['sla_breached' => ['isActive' => true]]]),
            ];
        }

        if ($overdueCount > 0) {
            $items[] = [
                'key' => 'overdue',
                'icon' => 'heroicon-o-exclamation-triangle',
                'color' => 'danger',
                'title' => trans_choice('admin.widgets.action_required.overdue_invoices', $overdueCount, ['count' => $overdueCount]),
                'body' => __('admin.widgets.action_required.overdue_invoices_body', ['amount' => number_format((float) $overdueAmount, 0)]),
                'url' => InvoiceResource::getUrl('index', ['tableFilters' => ['overdue_only' => ['isActive' => true]]]),
            ];
        }

        if ($expiringCriticalCount > 0) {
            $items[] = [
                'key' => 'expiring_critical',
                'icon' => 'heroicon-o-clock',
                'color' => 'danger',
                'title' => trans_choice('admin.widgets.action_required.expiring_critical', $expiringCriticalCount, ['count' => $expiringCriticalCount]),
                'body' => __('admin.widgets.action_required.expiring_critical_body'),
                'url' => LeaseResource::getUrl('index', ['tableFilters' => ['expiring_soon' => ['isActive' => true]]]),
            ];
        }

        if ($expiringSoonCount > 0) {
            $items[] = [
                'key' => 'expiring_soon',
                'icon' => 'heroicon-o-calendar',
                'color' => 'warning',
                'title' => trans_choice('admin.widgets.action_required.expiring_soon', $expiringSoonCount, ['count' => $expiringSoonCount]),
                'body' => __('admin.widgets.action_required.expiring_soon_body'),
                'url' => LeaseResource::getUrl('index', ['tableFilters' => ['expiring_soon' => ['isActive' => true]]]),
            ];
        }

        if ($vacantCount > 0) {
            $items[] = [
                'key' => 'vacant',
                'icon' => 'heroicon-o-building-storefront',
                'color' => 'info',
                'title' => trans_choice('admin.widgets.action_required.vacant_units', $vacantCount, ['count' => $vacantCount]),
                'body' => __('admin.widgets.action_required.vacant_units_body'),
                'url' => UnitResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'vacant']]]),
            ];
        }

        if ($unbilledLeasesCount > 0) {
            $items[] = [
                'key' => 'unbilled',
                'icon' => 'heroicon-o-document-plus',
                'color' => 'warning',
                'title' => trans_choice('admin.widgets.action_required.unbilled_leases', $unbilledLeasesCount, ['count' => $unbilledLeasesCount]),
                'body' => __('admin.widgets.action_required.unbilled_leases_body'),
                'url' => InvoiceResource::getUrl('index'),
            ];
        }

        return ['items' => $items];
    }
}
