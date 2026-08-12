<?php

namespace App\Filament\Portal\Widgets;

use App\Filament\Portal\Resources\Invoices\InvoiceResource;
use App\Filament\Portal\Resources\Leases\LeaseResource;
use App\Filament\Portal\Resources\Payments\PaymentResource;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\Portal;
use App\Support\ResourceLink;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AccountBalance extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        /** @var Tenant|null $tenant */
        $tenant = Portal::tenant();

        if (! $tenant) {
            return [];
        }

        $outstanding = $tenant->outstandingBalance();
        $overdueCount = (int) $tenant->invoices()->where('status', 'overdue')->count();
        $activeLeases = (int) $tenant->activeLeases()->count();
        $paidLifetime = (float) $tenant->payments()
            ->whereIn('status', Payment::RECEIVED_STATUSES)
            ->sum('amount');
        // Credit on account = money the tenant has paid that isn't yet applied to an invoice.
        $credit = $tenant->creditBalance();

        // Every figure links to the rows behind it, built through ResourceLink so the query-string
        // alias cannot be got wrong (see that class — nine admin cards shipped with the Filament v3
        // property name and silently landed on unfiltered lists).
        //
        // A tenant reading "EGP 48,000 outstanding" or "3 overdue" had nowhere to click: the number
        // named a problem and left them to find it in a register they may have a year of. This is
        // the same defect the admin dashboard carried, on the surface where the reader has the
        // least patience for it.
        return array_values(array_filter([
            Stat::make(__('admin.widgets.account_balance.outstanding_balance'), 'EGP '.number_format($outstanding, 2))
                ->description($outstanding > 0
                    ? __('admin.widgets.account_balance.outstanding_action')
                    : __('admin.widgets.account_balance.all_clear'))
                ->descriptionIcon($outstanding > 0 ? 'heroicon-m-credit-card' : 'heroicon-m-check-circle')
                ->color($outstanding > 0 ? 'danger' : 'success')
                // Everything still carrying a balance, oldest first — the order they should pay in.
                ->url($outstanding > 0
                    ? ResourceLink::indexWhere(InvoiceResource::class, 'unpaid_only', 'due_date:asc')
                    : null),

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
                ->color($overdueCount > 0 ? 'danger' : 'success')
                // The overdue subset specifically, not everything unpaid — the tenant clicked a
                // count of overdue invoices and must land on exactly those.
                ->url($overdueCount > 0
                    ? ResourceLink::indexSelect(InvoiceResource::class, 'status', 'overdue', 'due_date:asc')
                    : null),

            Stat::make(__('admin.widgets.account_balance.active_leases'), (string) $activeLeases)
                ->description(__('admin.widgets.account_balance.active_leases_desc'))
                ->descriptionIcon('heroicon-m-document-text')
                ->color('info')
                ->url(LeaseResource::getUrl('index')),

            Stat::make(__('admin.widgets.account_balance.lifetime_paid'), 'EGP '.number_format($paidLifetime, 0))
                ->description(__('admin.widgets.account_balance.lifetime_paid_desc'))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success')
                ->url(PaymentResource::getUrl('index')),
        ]));
    }
}
