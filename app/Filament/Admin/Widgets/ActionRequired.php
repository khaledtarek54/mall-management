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
        return ['manager', 'leasing', 'operations'];
    }

    protected string $view = 'filament.admin.widgets.action-required';

    protected static ?int $sort = 0;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function getViewData(): array
    {
        $now = Carbon::now();
        $assetId = \App\Support\TenantScope::currentAssetId();

        $invoiceBase = fn () => $assetId
            ? Invoice::whereHas('lease.unit', fn ($q) => $q->where('asset_id', $assetId))
            : Invoice::query();

        $leaseBase = fn () => $assetId
            ? Lease::whereHas('unit', fn ($q) => $q->where('asset_id', $assetId))
            : Lease::query();

        $unitBase = fn () => $assetId
            ? Unit::where('asset_id', $assetId)
            : Unit::query();

        $maintBase = fn () => $assetId
            ? MaintenanceRequest::whereHas('unit', fn ($q) => $q->where('asset_id', $assetId))
            : MaintenanceRequest::query();

        $overdueCount = $invoiceBase()->where('balance', '>', 0)->where('due_date', '<', $now)->count();
        $overdueAmount = $invoiceBase()->where('balance', '>', 0)->where('due_date', '<', $now)->sum('balance');

        $expiringCriticalCount = $leaseBase()->where('status', 'active')
            ->whereBetween('expiry_date', [$now, (clone $now)->addDays(30)])
            ->count();

        $expiringSoonCount = $leaseBase()->where('status', 'active')
            ->whereBetween('expiry_date', [(clone $now)->addDays(31), (clone $now)->addDays(90)])
            ->count();

        $vacantCount = $unitBase()->where('status', 'vacant')->count();

        $urgentMaintenanceCount = $maintBase()->whereIn('status', MaintenanceRequest::OPEN_STATUSES)
            ->where('priority', 'urgent')
            ->count();

        $slaBreachedCount = $maintBase()->whereIn('status', MaintenanceRequest::OPEN_STATUSES)
            ->whereNotNull('target_resolution_at')
            ->where('target_resolution_at', '<', $now)
            ->count();

        $monthStart = (clone $now)->startOfMonth();
        $monthEnd = (clone $now)->endOfMonth();
        $unbilledLeasesCount = $leaseBase()->where('status', 'active')
            ->whereDoesntHave('invoices', function ($q) use ($monthStart, $monthEnd) {
                $q->whereBetween('period_start', [$monthStart, $monthEnd]);
            })
            ->count();

        $items = [];
        $maintenanceEnabled = \App\Support\Modules::enabled('maintenance');

        // Each card pre-applies the right filter AND sorts the offending
        // rows to the top, so clicking lands the operator on the work
        // they need to do, not on a table they then have to re-sort.

        if ($maintenanceEnabled && $urgentMaintenanceCount > 0) {
            $items[] = [
                'key' => 'urgent_maintenance',
                'icon' => 'heroicon-o-wrench-screwdriver',
                'color' => 'danger',
                'title' => trans_choice('admin.widgets.action_required.urgent_maintenance', $urgentMaintenanceCount, ['count' => $urgentMaintenanceCount]),
                'body' => __('admin.widgets.action_required.urgent_maintenance_body'),
                'url' => MaintenanceRequestResource::getUrl('index', [
                    'filters' => ['priority' => ['value' => 'urgent']],
                    'sort' => 'submitted_at:asc',
                ]),
            ];
        }

        if ($maintenanceEnabled && $slaBreachedCount > 0) {
            $items[] = [
                'key' => 'sla_breached',
                'icon' => 'heroicon-o-clock',
                'color' => 'danger',
                'title' => trans_choice('admin.widgets.action_required.sla_breached', $slaBreachedCount, ['count' => $slaBreachedCount]),
                'body' => __('admin.widgets.action_required.sla_breached_body'),
                'url' => MaintenanceRequestResource::getUrl('index', [
                    'filters' => ['sla_breached' => ['isActive' => true]],
                    'sort' => 'target_resolution_at:asc',
                ]),
            ];
        }

        if ($overdueCount > 0) {
            $items[] = [
                'key' => 'overdue',
                'icon' => 'heroicon-o-exclamation-triangle',
                'color' => 'danger',
                'title' => trans_choice('admin.widgets.action_required.overdue_invoices', $overdueCount, ['count' => $overdueCount]),
                'body' => __('admin.widgets.action_required.overdue_invoices_body', ['amount' => number_format((float) $overdueAmount, 0)]),
                'url' => InvoiceResource::getUrl('index', [
                    'filters' => ['overdue_only' => ['isActive' => true]],
                    'sort' => 'due_date:asc',
                ]),
            ];
        }

        if ($expiringCriticalCount > 0) {
            $items[] = [
                'key' => 'expiring_critical',
                'icon' => 'heroicon-o-clock',
                'color' => 'danger',
                'title' => trans_choice('admin.widgets.action_required.expiring_critical', $expiringCriticalCount, ['count' => $expiringCriticalCount]),
                'body' => __('admin.widgets.action_required.expiring_critical_body'),
                'url' => LeaseResource::getUrl('index', [
                    'filters' => ['expiring_soon' => ['isActive' => true]],
                    'sort' => 'expiry_date:asc',
                ]),
            ];
        }

        if ($expiringSoonCount > 0) {
            $items[] = [
                'key' => 'expiring_soon',
                'icon' => 'heroicon-o-calendar',
                'color' => 'warning',
                'title' => trans_choice('admin.widgets.action_required.expiring_soon', $expiringSoonCount, ['count' => $expiringSoonCount]),
                'body' => __('admin.widgets.action_required.expiring_soon_body'),
                'url' => LeaseResource::getUrl('index', [
                    'filters' => ['expiring_soon' => ['isActive' => true]],
                    'sort' => 'expiry_date:asc',
                ]),
            ];
        }

        if ($vacantCount > 0) {
            $items[] = [
                'key' => 'vacant',
                'icon' => 'heroicon-o-building-storefront',
                'color' => 'info',
                'title' => trans_choice('admin.widgets.action_required.vacant_units', $vacantCount, ['count' => $vacantCount]),
                'body' => __('admin.widgets.action_required.vacant_units_body'),
                'url' => UnitResource::getUrl('index', [
                    'filters' => ['status' => ['value' => 'vacant']],
                    'sort' => 'area_sqm:desc',
                ]),
            ];
        }

        if ($unbilledLeasesCount > 0) {
            // "Unbilled leases" wants the operator on the Leases page filtered
            // to active leases — that's where they trigger Monthly Billing.
            // Sort newest-first so leases that just commenced surface fastest.
            $items[] = [
                'key' => 'unbilled',
                'icon' => 'heroicon-o-document-plus',
                'color' => 'warning',
                'title' => trans_choice('admin.widgets.action_required.unbilled_leases', $unbilledLeasesCount, ['count' => $unbilledLeasesCount]),
                'body' => __('admin.widgets.action_required.unbilled_leases_body'),
                'url' => LeaseResource::getUrl('index', [
                    'filters' => ['status' => ['value' => 'active']],
                    'sort' => 'commencement_date:desc',
                ]),
            ];
        }

        return ['items' => $items];
    }
}
