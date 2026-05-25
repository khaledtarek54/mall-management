<?php

namespace App\Services\Reports;

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Read-only query layer that backs the Reports module. Service-layer so the
 * math can be locked by PHPUnit without spinning up a browser, and so the
 * same numbers feed PDFs, Filament tables, and (eventually) the API.
 */
class ReportService
{
    /**
     * Snapshot of a single month for the finance team's monthly close.
     *
     * @return array{
     *   period:string, period_label:string,
     *   invoices: array{count:int, total:float, vat:float, by_status:array<string,array{count:int,total:float}>},
     *   payments: array{count:int, total:float, by_method:array<string,float>},
     *   ar_aging: array<string,array{count:int,total:float}>,
     *   outstanding_total: float,
     *   credit_notes: array{count:int, total_issued:float, total_applied:float},
     *   revenue_by_type: array<string,float>,
     *   collections_rate: float,
     * }
     */
    public function monthlyClose(?CarbonImmutable $period = null): array
    {
        $period = ($period ?? CarbonImmutable::now())->startOfMonth();
        $monthStart = $period;
        $monthEnd = $period->endOfMonth();

        $invoicesInMonth = TenantScope::applyTo(Invoice::query(), 'lease.unit')
            ->whereBetween('issue_date', [$monthStart, $monthEnd])
            ->get();

        $invoicesByStatus = $invoicesInMonth->groupBy('status')->map(fn ($group) => [
            'count' => $group->count(),
            'total' => round((float) $group->sum('total'), 2),
        ])->all();

        $paymentsInMonth = TenantScope::applyTo(Payment::query(), 'invoices.lease.unit')
            ->whereBetween('payment_date', [$monthStart, $monthEnd])
            ->where('status', 'captured')
            ->get();

        $paymentsByMethod = $paymentsInMonth
            ->groupBy('method')
            ->map(fn ($group) => round((float) $group->sum('amount'), 2))
            ->all();

        $revenueByType = $this->revenueByType($monthStart, $monthEnd);

        $arAging = $this->arAgingBuckets($monthEnd);
        $outstandingTotal = array_sum(array_column($arAging, 'total'));

        $creditNotes = $this->scopedCreditNotes()
            ->whereBetween('issue_date', [$monthStart, $monthEnd])
            ->whereIn('status', ['issued', 'applied'])
            ->get();

        $expectedThisMonth = (float) $invoicesInMonth->sum('total');
        $collectedThisMonth = (float) $paymentsInMonth->sum('amount');
        $collectionsRate = $expectedThisMonth > 0
            ? round(($collectedThisMonth / $expectedThisMonth) * 100, 1)
            : 0.0;

        return [
            'period' => $period->format('Y-m'),
            'period_label' => $period->locale(app()->getLocale())->isoFormat('MMMM YYYY'),
            'invoices' => [
                'count' => $invoicesInMonth->count(),
                'total' => round((float) $invoicesInMonth->sum('total'), 2),
                'vat' => round((float) $invoicesInMonth->sum('vat_amount'), 2),
                'by_status' => $invoicesByStatus,
            ],
            'payments' => [
                'count' => $paymentsInMonth->count(),
                'total' => round((float) $paymentsInMonth->sum('amount'), 2),
                'by_method' => $paymentsByMethod,
            ],
            'ar_aging' => $arAging,
            'outstanding_total' => round($outstandingTotal, 2),
            'credit_notes' => [
                'count' => $creditNotes->count(),
                'total_issued' => round((float) $creditNotes->sum('total'), 2),
                'total_applied' => round((float) $creditNotes->sum('applied_amount'), 2),
            ],
            'revenue_by_type' => $revenueByType,
            'collections_rate' => $collectionsRate,
        ];
    }

    /**
     * AR aging buckets as of a given date (defaults to today). Buckets are
     * based on `due_date` relative to the reference date.
     *
     * @return array<string,array{count:int,total:float}>
     */
    public function arAgingBuckets(?CarbonImmutable $asOf = null): array
    {
        $asOf = $asOf ?? CarbonImmutable::now()->endOfDay();

        $openInvoices = TenantScope::applyTo(Invoice::query(), 'lease.unit')
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->where('balance', '>', 0)
            ->whereDate('issue_date', '<=', $asOf)
            ->get();

        $buckets = [
            'current' => ['count' => 0, 'total' => 0.0],
            'd_1_30' => ['count' => 0, 'total' => 0.0],
            'd_31_60' => ['count' => 0, 'total' => 0.0],
            'd_61_90' => ['count' => 0, 'total' => 0.0],
            'd_90_plus' => ['count' => 0, 'total' => 0.0],
        ];

        foreach ($openInvoices as $invoice) {
            $daysOverdue = $invoice->due_date?->diffInDays($asOf, false) ?? 0;
            $key = match (true) {
                $daysOverdue <= 0 => 'current',
                $daysOverdue <= 30 => 'd_1_30',
                $daysOverdue <= 60 => 'd_31_60',
                $daysOverdue <= 90 => 'd_61_90',
                default => 'd_90_plus',
            };
            $buckets[$key]['count']++;
            $buckets[$key]['total'] += (float) $invoice->balance;
        }

        foreach ($buckets as $k => $v) {
            $buckets[$k]['total'] = round($v['total'], 2);
        }

        return $buckets;
    }

    /**
     * Return open invoices in a specific aging bucket, with tenant context.
     * Used by the AR Aging drilldown page.
     */
    public function arAgingDrilldown(string $bucket, ?CarbonImmutable $asOf = null): Collection
    {
        $asOf = $asOf ?? CarbonImmutable::now()->endOfDay();

        [$min, $max] = match ($bucket) {
            'current' => [PHP_INT_MIN, 0],
            'd_1_30' => [1, 30],
            'd_31_60' => [31, 60],
            'd_61_90' => [61, 90],
            'd_90_plus' => [91, PHP_INT_MAX],
            default => throw new \InvalidArgumentException("Unknown bucket: {$bucket}"),
        };

        return TenantScope::applyTo(Invoice::query(), 'lease.unit')
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->where('balance', '>', 0)
            ->with(['tenant', 'lease.unit'])
            ->get()
            ->filter(function (Invoice $invoice) use ($asOf, $min, $max) {
                $days = (int) ($invoice->due_date?->diffInDays($asOf, false) ?? 0);
                return $days >= $min && $days <= $max;
            })
            ->sortByDesc('balance')
            ->values();
    }

    /**
     * Top N tenants by outstanding AR. Useful for the collections team.
     *
     * @return array<int,array{tenant:Tenant, total_outstanding:float, days_overdue_avg:int, invoice_count:int}>
     */
    public function topDelinquentTenants(int $limit = 10): array
    {
        $now = CarbonImmutable::now();

        $openInvoices = TenantScope::applyTo(Invoice::query(), 'lease.unit')
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->where('balance', '>', 0)
            ->whereDate('due_date', '<', $now)
            ->with('tenant')
            ->get();

        $byTenant = $openInvoices->groupBy('tenant_id')->map(function ($group) use ($now) {
            $tenant = $group->first()->tenant;
            $totalOutstanding = (float) $group->sum('balance');
            $daysOverdueAvg = (int) $group->avg(fn (Invoice $i) => $i->due_date?->diffInDays($now) ?? 0);

            return [
                'tenant' => $tenant,
                'total_outstanding' => round($totalOutstanding, 2),
                'days_overdue_avg' => $daysOverdueAvg,
                'invoice_count' => $group->count(),
            ];
        });

        return $byTenant
            ->sortByDesc('total_outstanding')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Revenue billed per invoice-item type for a date range.
     * @return array<string,float>
     */
    private function revenueByType(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $query = \App\Models\InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereBetween('invoices.issue_date', [$start, $end])
            ->whereNotIn('invoices.status', ['cancelled', 'draft']);

        if ($assetId = TenantScope::currentAssetId()) {
            $query->whereExists(function ($q) use ($assetId) {
                $q->select(\DB::raw(1))
                  ->from('leases')
                  ->join('units', 'units.id', '=', 'leases.unit_id')
                  ->whereColumn('leases.id', 'invoices.lease_id')
                  ->where('units.asset_id', $assetId);
            });
        }

        $rows = $query
            ->selectRaw('invoice_items.type, SUM(invoice_items.amount) AS subtotal')
            ->groupBy('invoice_items.type')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->type] = round((float) $row->subtotal, 2);
        }
        return $out;
    }

    /**
     * Credit notes scoped to the current property. Mirrors CreditNoteResource:
     * standalone (no lease_id) credit notes are tenant-level adjustments and
     * remain visible across properties.
     */
    private function scopedCreditNotes(): \Illuminate\Database\Eloquent\Builder
    {
        $query = \App\Models\CreditNote::query();

        if ($assetId = TenantScope::currentAssetId()) {
            $query->where(function ($q) use ($assetId) {
                $q->whereNull('lease_id')
                  ->orWhereHas('lease.unit', fn ($q2) => $q2->where('asset_id', $assetId));
            });
        }

        return $query;
    }
}
