<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Concerns\RoleScopedWidget;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\MaintenanceWorkOrders\MaintenanceWorkOrderResource;
use App\Filament\Admin\Resources\PostDatedCheques\PostDatedChequeResource;
use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Lease;
use App\Models\MaintenanceWorkOrder;
use App\Models\PostDatedCheque;
use App\Models\TenantRequest;
use App\Models\Unit;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Support\Modules;
use App\Support\ResourceLink;
use App\Support\TenantScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class ActionRequired extends Widget
{
    use RoleScopedWidget;

    /**
     * Each card's `key` → the permission that governs the register it links to.
     *
     * The widget is one panel shown to managers, leasing, operations, accounting and the
     * maintenance coordinator, but its cards are not one audience: an operations user was being
     * told "9 overdue invoices — EGP 275,671 unpaid past due date" and handed a link that would
     * have 403'd them. Gating per card on the same `{module}.view` permission the target resource
     * uses means the alert list is exactly the work this user can actually go and do.
     *
     * @var array<string, string>
     */
    private const CARD_PERMISSIONS = [
        'urgent_requests' => 'requests.view',
        'sla_breached' => 'requests.view',
        'wo_sla_breached' => 'preventive_maintenance.view',
        'wo_response_breached' => 'preventive_maintenance.view',
        'ledger_without_property' => 'journal_entries.view',
        'vendor_documents' => 'vendors.view',
        'contract_notice' => 'vendors.view',
        'matured_cheques' => 'post_dated_cheques.view',
        'overdue' => 'invoices.view',
        'unbilled' => 'invoices.view',
        'option_closing' => 'leases.view',
        'holdover' => 'leases.view',
        'expiring_critical' => 'leases.view',
        'expiring_soon' => 'leases.view',
        'vacant' => 'units.view',
        // Repointed to the Leases list (the declarations register cannot show a MISSING
        // declaration), so it gates on the permission that list needs.
        'missing_sales' => 'leases.view',
    ];

    protected string $view = 'filament.admin.widgets.action-required';

    protected static ?int $sort = 0;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function getViewData(): array
    {
        $now = Carbon::now();
        // Property isolation: visibleAssetIds() keeps a restricted user pinned to their
        // set in All-Properties mode (currentAssetId() is null there → portfolio leak).
        $assetIds = TenantScope::visibleAssetIds();

        $invoiceBase = fn () => $assetIds !== null
            ? Invoice::whereHas('lease.unit', fn ($q) => $q->whereIn('asset_id', $assetIds))
            : Invoice::query();

        $leaseBase = fn () => $assetIds !== null
            ? Lease::whereHas('unit', fn ($q) => $q->whereIn('asset_id', $assetIds))
            : Lease::query();

        $unitBase = fn () => $assetIds !== null
            ? Unit::whereIn('asset_id', $assetIds)
            : Unit::query();

        $maintBase = fn () => $assetIds !== null
            ? TenantRequest::whereHas('unit', fn ($q) => $q->whereIn('asset_id', $assetIds))
            : TenantRequest::query();

        // Work orders carry asset_id directly (no unit hop) — a common-area job has no unit.
        $workOrderBase = fn () => $assetIds !== null
            ? MaintenanceWorkOrder::whereIn('asset_id', $assetIds)
            : MaintenanceWorkOrder::query();

        $overdueCount = $invoiceBase()->where('balance', '>', 0)->where('due_date', '<', $now)->count();
        $overdueAmount = $invoiceBase()->where('balance', '>', 0)->where('due_date', '<', $now)->sum('balance');

        // Holdover: active leases PAST their end date whose billing has silently stopped, so
        // surface it prominently. `holdoverNeedingAction()`, not `holdover()`: once an operator has
        // converted the lease to holdover billing (LE-04) the decision has been made and the rent
        // is flowing again — leaving it here would train everyone to ignore a card that never
        // empties. The lease is still findable under the table's Holdover filter.
        $holdoverCount = $leaseBase()->holdoverNeedingAction()->count();

        // An option notice window CLOSING is the one critical date that cannot be recovered once
        // missed: a renewal right lapses and the tenant loses it, or a break goes unexercised. The
        // widget already carried lease EXPIRIES and vendor contract notices; options were the gap
        // in the "what needs action" list (UX-09), and they are the item with the hardest deadline.
        $optionClosingCount = $leaseBase()
            ->whereHas('options', fn ($q) => $q
                ->where('status', 'open')
                ->whereNotNull('latest_notice_date')
                ->whereBetween('latest_notice_date', [$now, (clone $now)->addDays(90)]))
            ->count();

        // Vendors are a SHARED portfolio catalog, so "whose problem is it" comes from engagement:
        // only certs on vendors under an active contract at a property this user can see. Counting
        // the raw catalog would show a restricted manager another mall's compliance work.
        $coiCount = Vendor::query()
            ->documentsNeedAttention()
            ->when($assetIds !== null, fn ($q) => $q->whereHas(
                'contracts',
                fn ($c) => $c->where('status', 'active')->whereIn('asset_id', $assetIds),
            ))
            ->count();

        // Contracts at their NOTICE deadline — the date a decision is actually due. A contract
        // carries its own asset_id (null = portfolio-wide), so it scopes directly.
        $noticeDueCount = VendorContract::query()
            ->noticeDue()
            ->when($assetIds !== null, fn ($q) => $q->whereIn('asset_id', $assetIds))
            ->count();

        // Post-dated cheques matured but not yet cleared — money the register expected by now. A PDC
        // carries asset_id directly, so it scopes like a work order. Surfaces the register's core
        // value where overdue invoices already are, off the same scope as the nightly scan.
        $maturedChequeCount = PostDatedCheque::query()
            ->maturedUncleared()
            ->when($assetIds !== null, fn ($q) => $q->whereIn('asset_id', $assetIds))
            ->count();

        $expiringCriticalCount = $leaseBase()->where('status', 'active')
            ->whereBetween('expiry_date', [$now, (clone $now)->addDays(30)])
            ->count();

        $expiringSoonCount = $leaseBase()->where('status', 'active')
            ->whereBetween('expiry_date', [(clone $now)->addDays(31), (clone $now)->addDays(90)])
            ->count();

        $vacantCount = $unitBase()->where('status', 'vacant')->count();

        $urgentRequestCount = $maintBase()->whereIn('status', TenantRequest::OPEN_STATUSES)
            ->where('priority', 'urgent')
            ->count();

        $slaBreachedCount = $maintBase()->whereIn('status', TenantRequest::OPEN_STATUSES)
            ->whereNotNull('target_resolution_at')
            ->where('target_resolution_at', '<', $now)
            ->count();

        // FR-CM-08 — a breached corrective job rang the bell but stayed off the dashboard,
        // while breached tenant requests were shown. Same urgency, same card.
        $woSlaBreachedCount = $workOrderBase()->corrective()->open()
            ->whereNotNull('target_resolution_at')
            ->where('target_resolution_at', '<', $now)
            ->count();

        // The other half of FR-CM-08's detection. A job nobody has ACCEPTED has no resolution
        // deadline yet by design (FR-CM-07), so it could never appear in the count above — which
        // made "nobody has looked at this" the one maintenance failure the dashboard was blind to.
        $woUnansweredCount = $workOrderBase()->responseBreached()->count();

        // Posted money in no property's books. A null asset is a deliberate choice — the journal
        // form offers it as "consolidated" — but every owner statement is generated per asset, so
        // such an entry reaches NO statement while the portfolio-wide trial balance still balances.
        // Nothing counted them on any screen.
        $ledgerWithoutPropertyCount = JournalEntry::query()->withoutProperty()->count();

        $monthStart = (clone $now)->startOfMonth();
        $monthEnd = (clone $now)->endOfMonth();
        $unbilledLeasesCount = $leaseBase()->where('status', 'active')
            ->whereDoesntHave('invoices', function ($q) use ($monthStart, $monthEnd) {
                $q->whereBetween('period_start', [$monthStart, $monthEnd]);
            })
            ->get()
            // Only flag leases actually DUE to bill this month. isBillingCycleStart() is false during
            // the fit-out grace (month < first billable) AND on a quarterly/annual lease's off-cycle
            // months — in both cases the lease legitimately has no invoice, so it isn't "unbilled".
            ->filter(fn ($lease) => $lease->isBillingCycleStart(CarbonImmutable::instance($monthStart)))
            ->count();

        // Percentage-rent leases with NO sales declaration for the closed (previous) month — the
        // tenant hasn't reported, so their overage can't be billed (a silent revenue leak). Only
        // leases actually billable that month (commenced, past fit-out grace).
        $prevMonthStart = (clone $now)->subMonthNoOverflow()->startOfMonth();
        $missingSalesCount = $leaseBase()->where('status', 'active')
            ->where('has_percentage_rent', true)
            ->whereNotNull('commencement_date')
            ->whereDate('commencement_date', '<=', (clone $prevMonthStart)->endOfMonth())
            ->whereDoesntHave('salesDeclarations', fn ($q) => $q->whereDate('period_start', $prevMonthStart))
            ->get()
            ->filter(function ($lease) use ($prevMonthStart) {
                $firstBillable = $lease->firstBillableMonth();

                return $firstBillable === null || $firstBillable->lessThanOrEqualTo(CarbonImmutable::instance($prevMonthStart));
            })
            ->count();

        $items = [];
        $requestsEnabled = Modules::enabled('requests');
        $ppmEnabled = Modules::enabled('preventive_maintenance');

        // Each card pre-applies the right filter AND sorts the offending
        // rows to the top, so clicking lands the operator on the work
        // they need to do, not on a table they then have to re-sort.

        if ($requestsEnabled && $urgentRequestCount > 0) {
            $items[] = [
                'key' => 'urgent_requests',
                'icon' => 'heroicon-o-wrench-screwdriver',
                'color' => 'danger',
                'title' => trans_choice('admin.widgets.action_required.urgent_requests', $urgentRequestCount, ['count' => $urgentRequestCount]),
                'body' => __('admin.widgets.action_required.urgent_requests_body'),
                'url' => ResourceLink::indexSelect(TenantRequestResource::class, 'priority', 'urgent', 'submitted_at:asc'),
            ];
        }

        if ($requestsEnabled && $slaBreachedCount > 0) {
            $items[] = [
                'key' => 'sla_breached',
                'icon' => 'heroicon-o-clock',
                'color' => 'danger',
                'title' => trans_choice('admin.widgets.action_required.sla_breached', $slaBreachedCount, ['count' => $slaBreachedCount]),
                'body' => __('admin.widgets.action_required.sla_breached_body'),
                'url' => ResourceLink::indexWhere(TenantRequestResource::class, 'sla_breached', 'target_resolution_at:asc'),
            ];
        }

        if ($ppmEnabled && $woSlaBreachedCount > 0) {
            $items[] = [
                'key' => 'wo_sla_breached',
                'icon' => 'heroicon-o-clock',
                'color' => 'danger',
                'title' => trans_choice('admin.widgets.action_required.wo_sla_breached', $woSlaBreachedCount, ['count' => $woSlaBreachedCount]),
                'body' => __('admin.widgets.action_required.wo_sla_breached_body'),
                'url' => ResourceLink::indexWhere(MaintenanceWorkOrderResource::class, 'sla_breached', 'target_resolution_at:asc'),
            ];
        }

        if ($ppmEnabled && $woUnansweredCount > 0) {
            $items[] = [
                'key' => 'wo_response_breached',
                'icon' => 'heroicon-o-bell-alert',
                'color' => 'danger',
                'title' => trans_choice('admin.widgets.action_required.wo_response_breached', $woUnansweredCount, ['count' => $woUnansweredCount]),
                'body' => __('admin.widgets.action_required.wo_response_breached_body'),
                'url' => ResourceLink::indexWhere(MaintenanceWorkOrderResource::class, 'response_breached', 'target_response_at:asc'),
            ];
        }

        if ($ledgerWithoutPropertyCount > 0) {
            $items[] = [
                'key' => 'ledger_without_property',
                'icon' => 'heroicon-o-scale',
                'color' => 'warning',
                'title' => trans_choice('admin.widgets.action_required.ledger_without_property', $ledgerWithoutPropertyCount, ['count' => $ledgerWithoutPropertyCount]),
                'body' => __('admin.widgets.action_required.ledger_without_property_body'),
                'url' => ResourceLink::indexWhere(JournalEntryResource::class, 'without_property'),
            ];
        }

        if ($coiCount > 0) {
            $items[] = [
                'key' => 'vendor_documents',
                'icon' => 'heroicon-o-shield-exclamation',
                'color' => 'warning',
                'title' => trans_choice('admin.widgets.action_required.vendor_documents', $coiCount, ['count' => $coiCount]),
                'body' => __('admin.widgets.action_required.vendor_documents_body'),
                'url' => ResourceLink::indexWhere(VendorResource::class, 'document_attention', 'name:asc'),
            ];
        }

        if ($optionClosingCount > 0) {
            $items[] = [
                'key' => 'option_closing',
                'icon' => 'heroicon-o-hand-raised',
                'color' => 'warning',
                'title' => trans_choice('admin.widgets.action_required.option_closing', $optionClosingCount, ['count' => $optionClosingCount]),
                'body' => __('admin.widgets.action_required.option_closing_body'),
                // Options live inside the Lease resource, so land on the leases whose window is
                // closing — the `option_closing` filter added for exactly this.
                'url' => ResourceLink::indexWhere(LeaseResource::class, 'option_closing', 'expiry_date:asc'),
            ];
        }

        if ($noticeDueCount > 0) {
            $items[] = [
                'key' => 'contract_notice',
                'icon' => 'heroicon-o-calendar-days',
                'color' => 'danger',
                'title' => trans_choice('admin.widgets.action_required.contract_notice', $noticeDueCount, ['count' => $noticeDueCount]),
                'body' => __('admin.widgets.action_required.contract_notice_body'),
                // Land on the vendors whose contracts are at their notice deadline — the
                // `contract_notice_due` filter added for exactly this. It used to link to the
                // bare vendor list, which named the problem and then hid it.
                'url' => ResourceLink::indexWhere(VendorResource::class, 'contract_notice_due', 'name:asc'),
            ];
        }

        if ($maturedChequeCount > 0) {
            $items[] = [
                'key' => 'matured_cheques',
                'icon' => 'heroicon-o-banknotes',
                'color' => 'danger',
                'title' => trans_choice('admin.widgets.action_required.matured_cheques', $maturedChequeCount, ['count' => $maturedChequeCount]),
                'body' => __('admin.widgets.action_required.matured_cheques_body'),
                'url' => ResourceLink::indexWhere(PostDatedChequeResource::class, 'matured', 'cheque_date:asc'),
            ];
        }

        if ($overdueCount > 0) {
            $items[] = [
                'key' => 'overdue',
                'icon' => 'heroicon-o-exclamation-triangle',
                'color' => 'danger',
                'title' => trans_choice('admin.widgets.action_required.overdue_invoices', $overdueCount, ['count' => $overdueCount]),
                'body' => __('admin.widgets.action_required.overdue_invoices_body', ['amount' => number_format((float) $overdueAmount, 0)]),
                'url' => ResourceLink::indexWhere(InvoiceResource::class, 'overdue_only', 'due_date:asc'),
            ];
        }

        if ($holdoverCount > 0) {
            $items[] = [
                'key' => 'holdover',
                'icon' => 'heroicon-o-exclamation-triangle',
                'color' => 'danger',
                'title' => trans_choice('admin.widgets.action_required.holdover', $holdoverCount, ['count' => $holdoverCount]),
                'body' => __('admin.widgets.action_required.holdover_body'),
                'url' => ResourceLink::indexWhere(LeaseResource::class, 'holdover', 'expiry_date:asc'),
            ];
        }

        if ($expiringCriticalCount > 0) {
            $items[] = [
                'key' => 'expiring_critical',
                'icon' => 'heroicon-o-clock',
                'color' => 'danger',
                'title' => trans_choice('admin.widgets.action_required.expiring_critical', $expiringCriticalCount, ['count' => $expiringCriticalCount]),
                'body' => __('admin.widgets.action_required.expiring_critical_body'),
                'url' => ResourceLink::indexWhere(LeaseResource::class, 'expiring_soon', 'expiry_date:asc'),
            ];
        }

        if ($expiringSoonCount > 0) {
            $items[] = [
                'key' => 'expiring_soon',
                'icon' => 'heroicon-o-calendar',
                'color' => 'warning',
                'title' => trans_choice('admin.widgets.action_required.expiring_soon', $expiringSoonCount, ['count' => $expiringSoonCount]),
                'body' => __('admin.widgets.action_required.expiring_soon_body'),
                'url' => ResourceLink::indexWhere(LeaseResource::class, 'expiring_soon', 'expiry_date:asc'),
            ];
        }

        if ($vacantCount > 0) {
            $items[] = [
                'key' => 'vacant',
                'icon' => 'heroicon-o-building-storefront',
                'color' => 'info',
                'title' => trans_choice('admin.widgets.action_required.vacant_units', $vacantCount, ['count' => $vacantCount]),
                'body' => __('admin.widgets.action_required.vacant_units_body'),
                'url' => ResourceLink::indexSelect(UnitResource::class, 'status', 'vacant', 'area_sqm:desc'),
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
                'url' => ResourceLink::indexSelect(LeaseResource::class, 'status', 'active', 'commencement_date:desc'),
            ];
        }

        if ($missingSalesCount > 0) {
            // Percentage-rent tenants who haven't reported last month's sales — land the operator on
            // the declarations list so they can chase the report (and lock it once it arrives).
            $items[] = [
                'key' => 'missing_sales',
                'icon' => 'heroicon-o-presentation-chart-line',
                'color' => 'warning',
                'title' => trans_choice('admin.widgets.action_required.missing_sales', $missingSalesCount, ['count' => $missingSalesCount]),
                'body' => __('admin.widgets.action_required.missing_sales_body'),
                // The Leases list, not the declarations list: the leases with a MISSING
                // declaration are by definition absent from the declarations register, so
                // that link could never show the operator what the card had counted.
                'url' => ResourceLink::indexWhere(LeaseResource::class, 'missing_sales', 'commencement_date:desc'),
            ];
        }

        return ['items' => $this->visibleTo($items)];
    }

    /**
     * Drop the cards this user has no business acting on.
     *
     * A card whose key isn't mapped stays visible — a new alert should not vanish silently just
     * because its permission was forgotten. The mapping is the gate; the omission is loud.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function visibleTo(array $items): array
    {
        $user = Auth::user();

        return array_values(array_filter($items, function (array $item) use ($user): bool {
            $permission = self::CARD_PERMISSIONS[$item['key']] ?? null;

            return $permission === null || ($user?->can($permission) ?? false);
        }));
    }
}
