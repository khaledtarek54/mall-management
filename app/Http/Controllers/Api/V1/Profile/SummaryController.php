<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Announcement;
use App\Models\TenantRequest;
use App\Models\TenantSalesDeclaration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me/summary — one call that backs the zero-tap home screen:
 * money owed, open work, things needing the tenant's attention. Saves the app
 * from fanning out to balance + maintenance + declarations + notifications.
 */
class SummaryController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $tenant = $request->user();

        $openInvoices = $tenant->invoices()
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->get(['id', 'balance', 'due_date']);

        $overdue = (float) $openInvoices
            ->filter(fn ($inv) => $inv->balance > 0 && $inv->due_date && $inv->due_date->isPast())
            ->sum('balance');

        return $this->ok([
            // Money
            'outstanding' => round($tenant->outstandingBalance(), 2),
            'overdue' => round($overdue, 2),
            // Counts/bools are cast explicitly so the generated spec publishes
            // integer/boolean rather than falling back to `string` — the mobile
            // client decodes against the spec.
            'open_invoices' => (int) $openInvoices->where('balance', '>', 0)->count(),
            // 'issued' only — same filter Tenant::outstandingBalance() uses for
            // spendable credit (applied/void notes carry no remaining balance).
            'credit_available' => round((float) $tenant->creditNotes()
                ->where('status', 'issued')->sum('balance'), 2),
            'is_delinquent' => (bool) $tenant->isDelinquent(),

            // Open work.
            //
            // ⚠️ `open_maintenance` is a WIRE CONTRACT, not an identifier — the camelCase middleware
            // ships it as `openMaintenance`, and a released mobile client reads that key. It keeps
            // the old word deliberately: renaming it is a breaking API change that needs a version
            // bump and a client release, not a ride-along on an internal rename. The 2026-08-15
            // sweep renamed it and the contract test caught it. Everything BEHIND the key moved
            // (`tenantRequests()`, `TenantRequest`); only the key the app parses is frozen.
            'open_maintenance' => (int) $tenant->tenantRequests()
                ->whereIn('status', TenantRequest::OPEN_STATUSES)->count(),

            // Needs the tenant's attention
            'disputed_declarations' => (int) TenantSalesDeclaration::query()
                ->whereHas('lease', fn ($q) => $q->where('tenant_id', $tenant->getKey()))
                ->where('status', 'disputed')->count(),
            'can_declare_sales' => (bool) $tenant->leases()
                ->where('has_percentage_rent', true)->where('status', 'active')->exists(),
            'unread_notifications' => (int) $tenant->unreadNotifications()->count(),
            // Mall news the tenant has not opened. Counted off the recipient rows rather than the
            // notification inbox, because the two answer different questions: marking the bell
            // "all read" is a gesture at an inbox, while opening the notice is the thing an
            // operator is entitled to count. Same predicate as the feed itself, so the badge can
            // never show a number the list cannot produce.
            'unread_announcements' => (int) Announcement::query()
                ->liveFor($tenant)
                ->whereHas('recipients', fn ($q) => $q
                    ->where('tenant_id', $tenant->getKey())
                    ->whereNull('read_at'))
                ->count(),

            'currency' => 'EGP',
        ]);
    }
}
