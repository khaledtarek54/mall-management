<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Concerns\RoleScopedWidget;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Egyptian Tax Authority (ETA) e-invoicing compliance posture.
 * Showing this on the dashboard is *the* "we get Egypt, they don't" moment
 * for an Egyptian CFO — it tells them at a glance which invoices the
 * regulator has accepted, rejected, or hasn't seen yet.
 */
class EtaCompliance extends StatsOverviewWidget
{
    use RoleScopedWidget;

    // Compliance posture is a finance/operations signal.
    protected static function allowedRoles(): array
    {
        return ['manager', 'viewer'];
    }

    protected static function widgetModule(): ?string
    {
        return 'eta';
    }

    protected static ?int $sort = 7;

    protected function getStats(): array
    {
        // We only count invoices that have actually been issued (or beyond).
        // Drafts and cancelled invoices aren't part of the compliance posture.
        $base = \App\Support\TenantScope::applyTo(Invoice::query(), 'lease.unit')
            ->whereIn('status', ['issued', 'partially_paid', 'paid', 'overdue']);

        $valid = (clone $base)->where('eta_status', 'valid')->count();
        $submitted = (clone $base)->where('eta_status', 'submitted')->count();
        $rejected = (clone $base)->whereIn('eta_status', ['invalid', 'rejected'])->count();
        $pending = (clone $base)
            ->where(fn ($q) => $q->whereNull('eta_status')->orWhere('eta_status', 'pending'))
            ->count();

        $total = $valid + $submitted + $rejected + $pending;
        $validPct = $total > 0 ? round(($valid / $total) * 100) : 0;

        // Each tile links to the matching InvoicesTable filter (audit M08
        // D-24/D-26). Valid + Submitted hit the eta_status SelectFilter;
        // Rejected and Pending hit dedicated multi-value filters so the
        // counts on the tile match the rows on the destination list.
        return [
            Stat::make(__('admin.widgets.eta.valid'), number_format($valid))
                ->description(__('admin.widgets.eta.valid_desc', ['pct' => $validPct]))
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('success')
                ->url(InvoiceResource::getUrl('index', ['tableFilters' => ['eta_status' => ['value' => 'valid']]])),

            Stat::make(__('admin.widgets.eta.submitted'), number_format($submitted))
                ->description(__('admin.widgets.eta.submitted_desc'))
                ->descriptionIcon('heroicon-m-paper-airplane')
                ->color('info')
                ->url(InvoiceResource::getUrl('index', ['tableFilters' => ['eta_status' => ['value' => 'submitted']]])),

            Stat::make(__('admin.widgets.eta.rejected'), number_format($rejected))
                ->description(__('admin.widgets.eta.rejected_desc'))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($rejected > 0 ? 'danger' : 'gray')
                ->url(InvoiceResource::getUrl('index', ['tableFilters' => ['needs_eta_attention' => ['isActive' => true]]])),

            Stat::make(__('admin.widgets.eta.pending'), number_format($pending))
                ->description(__('admin.widgets.eta.pending_desc'))
                ->descriptionIcon('heroicon-m-clock')
                ->color($pending > 0 ? 'warning' : 'gray')
                ->url(InvoiceResource::getUrl('index', ['tableFilters' => ['eta_pending' => ['isActive' => true]]])),
        ];
    }

}
