<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Concerns\RoleScopedWidget;
use App\Filament\Admin\Resources\MaintenanceWorkOrders\MaintenanceWorkOrderResource;
use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Models\MaintenanceWorkOrder;
use App\Models\TenantRequest;
use App\Support\AssignmentScope;
use App\Support\Modules;
use App\Support\TenantScope;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * "What am I supposed to be doing?" — the technician's and the external vendor's whole dashboard.
 *
 * FR-USR-04 is explicit that a technician "sees only work assigned to them", and the codebase
 * already enforces that on the resource tables via `AssignmentScope`. It did not reach the
 * dashboard: both roles logged in to a blank page and had to go hunting through the sidebar for
 * their own jobs.
 *
 * The counts run through the SAME `AssignmentScope::apply()` the resources use, on top of
 * `TenantScope` — so a coordinator or mall admin who lands here sees the whole board (they hold
 * `*.view_all`), a technician sees only their own, and neither can see a property they are not
 * assigned to. Reusing the primitive is the point: a private `where('assigned_to', $id)` here
 * would drift the day the assignee column or the permission changes.
 */
class MyAssignedWork extends StatsOverviewWidget
{
    use RoleScopedWidget;

    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        $user = Auth::user();
        $stats = [];

        if (Modules::enabled('requests')) {
            $requests = AssignmentScope::apply(
                TenantScope::applyTo(TenantRequest::query(), 'unit'),
                'requests',
                'assigned_to',
                $user,
            )->whereNotIn('status', ['closed', 'cancelled', 'resolved']);

            $openRequests = (clone $requests)->count();
            $overdueRequests = (clone $requests)
                ->whereNotNull('target_resolution_at')
                ->where('target_resolution_at', '<', CarbonImmutable::now())
                ->count();

            $stats[] = Stat::make(__('admin.widgets.my_work.open_requests'), number_format($openRequests))
                ->description($overdueRequests > 0
                    ? __('admin.widgets.my_work.past_due', ['count' => $overdueRequests])
                    : __('admin.widgets.my_work.on_time'))
                ->descriptionIcon($overdueRequests > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($overdueRequests > 0 ? 'danger' : ($openRequests > 0 ? 'warning' : 'success'))
                ->url(TenantRequestResource::getUrl('index'));
        }

        if (Modules::enabled('preventive_maintenance')) {
            $workOrders = AssignmentScope::apply(
                TenantScope::applyTo(MaintenanceWorkOrder::query()),
                'preventive_maintenance',
                'assigned_to_user_id',
                $user,
            )->whereNotIn('status', ['done', 'cancelled']);

            $openWorkOrders = (clone $workOrders)->count();
            $dueToday = (clone $workOrders)
                ->whereNotNull('scheduled_for')
                ->whereDate('scheduled_for', '<=', CarbonImmutable::now()->toDateString())
                ->count();

            $stats[] = Stat::make(__('admin.widgets.my_work.open_work_orders'), number_format($openWorkOrders))
                ->description($dueToday > 0
                    ? __('admin.widgets.my_work.due_today', ['count' => $dueToday])
                    : __('admin.widgets.my_work.nothing_due'))
                ->descriptionIcon($dueToday > 0 ? 'heroicon-m-calendar-days' : 'heroicon-m-check-circle')
                ->color($dueToday > 0 ? 'warning' : ($openWorkOrders > 0 ? 'info' : 'success'))
                ->url(MaintenanceWorkOrderResource::getUrl('index'));
        }

        // Both modules off is a legitimate configuration, and a stats widget that returns an
        // empty array renders as a bare grey box. Say why instead.
        if ($stats === []) {
            $stats[] = Stat::make(__('admin.widgets.my_work.open_requests'), '—')
                ->description(__('admin.widgets.my_work.modules_off'))
                ->color('gray');
        }

        return $stats;
    }
}
