<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Concerns\RoleScopedWidget;
use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Filament\Admin\Resources\Payrolls\PayrollResource;
use App\Models\Employee;
use App\Models\Payroll;
use App\Support\TenantScope;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Headcount and the state of this month's payroll run.
 *
 * The HR role had **no dashboard at all**. HR's month is driven by one recurring question —
 * "is this month's payroll run done?" — so the widget answers it directly rather than showing a
 * count of employees and leaving the operator to go and look. A month with no run at all is
 * called out as such: the absence of a payroll is the thing worth alerting on, and a widget that
 * only summed existing runs would have shown a reassuring "EGP 0" instead.
 */
class PayrollStats extends StatsOverviewWidget
{
    use RoleScopedWidget;

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $monthStart = CarbonImmutable::now()->startOfMonth();

        $headcount = TenantScope::applyTo(Employee::query())->where('status', 'active')->count();
        $terminatedThisYear = TenantScope::applyTo(Employee::query())
            ->where('status', 'terminated')
            ->whereYear('terminated_on', CarbonImmutable::now()->year)
            ->count();

        $thisMonthsRun = TenantScope::applyTo(Payroll::query())
            ->whereYear('period_month', $monthStart->year)
            ->whereMonth('period_month', $monthStart->month)
            ->get();

        $netPaid = (float) $thisMonthsRun->sum('net_paid');
        $draftCount = $thisMonthsRun->where('status', 'draft')->count();
        $monthLabel = $monthStart->locale(app()->getLocale())->isoFormat('MMMM YYYY');

        // Three states, three different things for HR to do — no run yet, a run still in draft,
        // or approved and done.
        [$runValue, $runDesc, $runColor, $runIcon] = match (true) {
            $thisMonthsRun->isEmpty() => [
                __('admin.widgets.payroll.not_run'),
                __('admin.widgets.payroll.not_run_desc', ['month' => $monthLabel]),
                'warning',
                'heroicon-m-exclamation-triangle',
            ],
            $draftCount > 0 => [
                'EGP '.number_format($netPaid, 0),
                __('admin.widgets.payroll.awaiting_approval', ['count' => $draftCount]),
                'warning',
                'heroicon-m-clock',
            ],
            default => [
                'EGP '.number_format($netPaid, 0),
                __('admin.widgets.payroll.approved_desc', ['month' => $monthLabel]),
                'success',
                'heroicon-m-check-circle',
            ],
        };

        return [
            Stat::make(__('admin.widgets.payroll.headcount'), number_format($headcount))
                ->description(__('admin.widgets.payroll.headcount_desc'))
                ->descriptionIcon('heroicon-m-users')
                ->color('primary')
                ->url(EmployeeResource::getUrl('index')),

            Stat::make(__('admin.widgets.payroll.this_month'), $runValue)
                ->description($runDesc)
                ->descriptionIcon($runIcon)
                ->color($runColor)
                ->url(PayrollResource::getUrl('index')),

            Stat::make(__('admin.widgets.payroll.leavers'), number_format($terminatedThisYear))
                ->description(__('admin.widgets.payroll.leavers_desc', ['year' => CarbonImmutable::now()->year]))
                ->descriptionIcon('heroicon-m-arrow-right-on-rectangle')
                ->color($terminatedThisYear > 0 ? 'warning' : 'gray')
                ->url(EmployeeResource::getUrl('index')),
        ];
    }
}
