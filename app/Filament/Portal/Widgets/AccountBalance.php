<?php

namespace App\Filament\Portal\Widgets;

use App\Models\Tenant;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class AccountBalance extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        /** @var Tenant|null $tenant */
        $tenant = \App\Support\Portal::tenant();

        if (! $tenant) {
            return [];
        }

        $outstanding = $tenant->outstandingBalance();
        $overdueCount = (int) $tenant->invoices()->where('status', 'overdue')->count();
        $activeLeases = (int) $tenant->activeLeases()->count();
        $paidLifetime = (float) $tenant->payments()
            ->whereIn('status', \App\Models\Payment::RECEIVED_STATUSES)
            ->sum('amount');
        // Credit on account = money the tenant has paid that isn't yet applied to an invoice.
        $credit = $tenant->creditBalance();

        return array_values(array_filter([
            Stat::make(__('admin.widgets.account_balance.outstanding_balance'), 'EGP '.number_format($outstanding, 2))
                ->description($outstanding > 0
                    ? __('admin.widgets.account_balance.outstanding_action')
                    : __('admin.widgets.account_balance.all_clear'))
                ->descriptionIcon($outstanding > 0 ? 'heroicon-m-credit-card' : 'heroicon-m-check-circle')
                ->color($outstanding > 0 ? 'danger' : 'success'),

            // Only shown when there IS a credit — a routine tenant carries none, so don't clutter.
            $credit > 0
                ? Stat::make(__('admin.widgets.account_balance.credit_balance'), 'EGP '.number_format($credit, 2))
                    ->description(__('admin.widgets.account_balance.credit_balance_desc'))
                    ->descriptionIcon('heroicon-m-gift')
                    ->color('success')
                : null,

            Stat::make(__('admin.widgets.account_balance.overdue_invoices'), (string) $overdueCount)
                ->description($overdueCount > 0
                    ? __('admin.widgets.account_balance.overdue_action')
                    : __('admin.widgets.account_balance.no_overdue'))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($overdueCount > 0 ? 'danger' : 'success'),

            Stat::make(__('admin.widgets.account_balance.active_leases'), (string) $activeLeases)
                ->description(__('admin.widgets.account_balance.active_leases_desc'))
                ->descriptionIcon('heroicon-m-document-text')
                ->color('info'),

            Stat::make(__('admin.widgets.account_balance.lifetime_paid'), 'EGP '.number_format($paidLifetime, 0))
                ->description(__('admin.widgets.account_balance.lifetime_paid_desc'))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),
        ]));
    }
}
