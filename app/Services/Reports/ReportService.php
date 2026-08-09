<?php

namespace App\Services\Reports;

use App\Models\CreditNote;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\VendorBill;
use App\Support\CostNature;
use App\Services\ChargeScheduleService;
use App\Support\TenantScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only query layer that backs the Reports module. Service-layer so the
 * math can be locked by PHPUnit without spinning up a browser, and so the
 * same numbers feed PDFs, Filament tables, and (eventually) the API.
 */
class ReportService
{
    /**
     * The aging buckets, in order, and their day ranges (inclusive; `null` = unbounded).
     *
     * The single register the summary, the drill-down and the collections worklist all read —
     * see {@see agingBucketKey()} for why this is not allowed to be copied.
     */
    public const AGING_BUCKETS = [
        'current' => [null, 0],
        'd_1_30' => [1, 30],
        'd_31_60' => [31, 60],
        'd_61_90' => [61, 90],
        'd_90_plus' => [91, null],
    ];

    /**
     * Snapshot of a single month for the finance team's monthly close.
     *
     * @return array{
     *   period:string, period_label:string,
     *   invoices: array{count:int, total:float, vat:float, by_status:array<string,array{count:int,total:float}>},
     *   payments: array{count:int, total:float, by_method:array<string,float>},
     *   ar_aging: array<string,array{count:int,total:float}>,
     *   ar_aging_as_of: string,
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

        // "Billed" excludes draft (never issued) + cancelled (voided) — matching revenueByType()
        // below, so the report is internally consistent. Folding those into the headline inflated
        // billed revenue and understated the collections rate (real cash over a padded denominator).
        // by_status still lists every status, so cancelled/draft remain visible in the breakdown.
        $billable = $invoicesInMonth->whereNotIn('status', ['cancelled', 'draft']);

        $invoicesByStatus = $invoicesInMonth->groupBy('status')->map(fn ($group) => [
            'count' => $group->count(),
            'total' => round((float) $group->sum('total'), 2),
        ])->all();

        $paymentsInMonth = TenantScope::applyTo(Payment::query(), 'invoices.lease.unit')
            ->whereBetween('payment_date', [$monthStart, $monthEnd])
            ->whereIn('status', Payment::RECEIVED_STATUSES)
            ->get();

        $paymentsByMethod = $paymentsInMonth
            ->groupBy('method')
            ->map(fn ($group) => round((float) $group->sum('amount'), 2))
            ->all();

        $revenueByType = $this->revenueByType($monthStart, $monthEnd);

        // Age the receivables as at the period close — but never past today. Month-end of the
        // month currently being closed is a FUTURE date, and ageing to it declared invoices
        // late that aren't due yet (on the demo books: 81 invoices / EGP 1.01m shown as
        // "1–30 days" when only 2 were actually late). The drill-down ages as of a real day,
        // so the un-clamped bucket totals also disagreed with the invoices behind them.
        $agingAsOf = $monthEnd->isFuture() ? CarbonImmutable::now()->endOfDay() : $monthEnd;
        $arAging = $this->arAgingBuckets($agingAsOf);
        $outstandingTotal = array_sum(array_column($arAging, 'total'));

        $creditNotes = $this->scopedCreditNotes()
            ->whereBetween('issue_date', [$monthStart, $monthEnd])
            ->whereIn('status', ['issued', 'applied'])
            ->get();

        $expectedThisMonth = (float) $billable->sum('total');
        $collectedThisMonth = (float) $paymentsInMonth->sum('amount');
        $collectionsRate = $expectedThisMonth > 0
            ? round(($collectedThisMonth / $expectedThisMonth) * 100, 1)
            : 0.0;

        return [
            'period' => $period->format('Y-m'),
            'period_label' => $period->locale(app()->getLocale())->isoFormat('MMMM YYYY'),
            'invoices' => [
                'count' => $billable->count(),
                'total' => round((float) $billable->sum('total'), 2),
                'vat' => round((float) $billable->sum('vat_amount'), 2),
                'by_status' => $invoicesByStatus,
            ],
            'payments' => [
                'count' => $paymentsInMonth->count(),
                'total' => round((float) $paymentsInMonth->sum('amount'), 2),
                'by_method' => $paymentsByMethod,
            ],
            'ar_aging' => $arAging,
            // The day the buckets above were aged at — carried out of the service so the
            // drill-down can be opened on the SAME day and list exactly the invoices the
            // bucket counted, instead of re-ageing at "now".
            'ar_aging_as_of' => $agingAsOf->toDateString(),
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
     * Weekly operating-cost report (FR-FIN-02), split fixed vs variable.
     *
     * Reads the mall's direct expenses (Expense, dated `expense_date`) AND its vendor bills
     * (VendorBill, dated `bill_date`) — both carry the same category set, classified by
     * App\Support\CostNature — as the EX-VAT cost incurred (VAT is recoverable input tax, not a
     * cost). Weeks are ISO (Mon–Sun) and the range is pre-seeded so a week with no spend reads as
     * zero rather than vanishing from the trend. Property-scoped via TenantScope, so it respects
     * the selected mall (and a restricted user's visible set in All-mode).
     *
     * @return array{
     *   from:string, to:string,
     *   weeks: array<int, array{week_start:string, label:string, fixed:float, variable:float, total:float}>,
     *   totals: array{fixed:float, variable:float, total:float},
     * }
     */
    public function weeklySpend(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $to = ($to ?? CarbonImmutable::now())->endOfWeek(CarbonInterface::SUNDAY);
        // 12 ISO weeks by default. Force Monday-start so weeks are the business standard (and
        // stable across the ar locale, whose default first-day would otherwise shift them).
        $from = ($from ?? $to->subWeeks(11))->startOfWeek(CarbonInterface::MONDAY);

        // Pre-seed every week in the range so gaps read as 0.
        $weeks = [];
        for ($cursor = $from; $cursor <= $to; $cursor = $cursor->addWeek()) {
            $weeks[$cursor->format('o-\WW')] = [
                'week_start' => $cursor->toDateString(),
                'label' => $cursor->format('d/m').' – '.$cursor->endOfWeek(CarbonInterface::SUNDAY)->format('d/m'),
                'fixed' => 0.0,
                'variable' => 0.0,
            ];
        }

        $add = function (mixed $date, ?string $category, float $cost) use (&$weeks): void {
            $key = CarbonImmutable::parse((string) $date)->startOfWeek(CarbonInterface::MONDAY)->format('o-\WW');
            if (! isset($weeks[$key])) {
                return; // outside the seeded range — the queries are bounded, so a defensive no-op
            }
            // Explicit branch (not a dynamic `[$nature]` key) so the array shape stays resolvable.
            if (CostNature::forCategory($category) === CostNature::FIXED) {
                $weeks[$key]['fixed'] += $cost;
            } else {
                $weeks[$key]['variable'] += $cost;
            }
        };

        TenantScope::applyTo(Expense::query())
            ->where('status', 'recorded')
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->get(['expense_date', 'category', 'amount'])
            ->each(fn (Expense $e) => $add($e->expense_date, $e->category, round((float) $e->amount, 2)));

        TenantScope::applyTo(VendorBill::query())
            ->whereNotIn('status', VendorBill::NON_POSTABLE_STATUSES)
            ->whereBetween('bill_date', [$from->toDateString(), $to->toDateString()])
            ->get(['bill_date', 'category', 'total', 'vat_amount'])
            ->each(fn (VendorBill $b) => $add($b->bill_date, $b->category, round((float) $b->total - (float) $b->vat_amount, 2)));

        $rows = [];
        foreach ($weeks as $w) {
            $fixed = round($w['fixed'], 2);
            $variable = round($w['variable'], 2);
            $rows[] = [
                'week_start' => $w['week_start'],
                'label' => $w['label'],
                'fixed' => $fixed,
                'variable' => $variable,
                'total' => round($fixed + $variable, 2),
            ];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'weeks' => $rows,
            'totals' => [
                'fixed' => round((float) array_sum(array_column($rows, 'fixed')), 2),
                'variable' => round((float) array_sum(array_column($rows, 'variable')), 2),
                'total' => round((float) array_sum(array_column($rows, 'total')), 2),
            ],
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

        $buckets = array_map(
            fn () => ['count' => 0, 'total' => 0.0],
            self::AGING_BUCKETS,
        );

        foreach ($openInvoices as $invoice) {
            $key = self::agingBucketKey($invoice, $asOf);
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
        if (! array_key_exists($bucket, self::AGING_BUCKETS)) {
            throw new \InvalidArgumentException("Unknown bucket: {$bucket}");
        }

        $asOf = $asOf ?? CarbonImmutable::now()->endOfDay();

        return $this->openInvoicesAsOf($asOf)
            ->filter(fn (Invoice $invoice) => self::agingBucketKey($invoice, $asOf) === $bucket)
            ->sortByDesc('balance')
            ->values();
    }

    /**
     * The collections worklist: one row per tenant, their outstanding split across the buckets.
     *
     * The aging *summary* answers "how much is late"; this answers "who do I call, and about
     * what" — which is the question a collections clerk actually has. Sorted worst-first: deepest
     * bucket, then size.
     *
     * @return Collection<int, array{tenant:?\App\Models\Tenant, tenant_id:int, total:float, buckets:array<string,float>, invoice_count:int, oldest_days:int, last_payment_at:?\Illuminate\Support\Carbon}>
     */
    public function arCollectionsByTenant(?CarbonImmutable $asOf = null): Collection
    {
        $asOf = $asOf ?? CarbonImmutable::now()->endOfDay();

        return $this->openInvoicesAsOf($asOf)
            ->groupBy('tenant_id')
            ->map(function (Collection $invoices, $tenantId) use ($asOf) {
                $buckets = array_fill_keys(array_keys(self::AGING_BUCKETS), 0.0);
                $oldest = 0;

                foreach ($invoices as $invoice) {
                    $buckets[self::agingBucketKey($invoice, $asOf)] += (float) $invoice->balance;
                    $oldest = max($oldest, self::daysOverdue($invoice, $asOf));
                }

                return [
                    'tenant_id' => (int) $tenantId,
                    'tenant' => $invoices->first()->tenant,
                    'total' => round((float) $invoices->sum('balance'), 2),
                    'buckets' => array_map(fn (float $v) => round($v, 2), $buckets),
                    'invoice_count' => $invoices->count(),
                    'oldest_days' => $oldest,
                    // When they last actually paid anything — the single most useful signal for
                    // whether this is a slow payer or a stopped one.
                    'last_payment_at' => Payment::query()
                        ->where('tenant_id', $tenantId)
                        ->whereIn('status', Payment::RECEIVED_STATUSES)
                        ->max('payment_date'),
                ];
            })
            // Worst first: how deep, then how much. A tenant 120 days late for 10k needs the call
            // before one 5 days late for 100k.
            ->sortByDesc(fn (array $row) => [$row['oldest_days'], $row['total']])
            ->values();
    }

    /**
     * The RENT ROLL — one row per lease as at a date. The single most-used commercial report there
     * is, and Atriom had no version of it at all.
     *
     * "As at a date" is the whole point and the reason this could not exist before phase 1: the
     * rent used to be one mutable number, so a rent roll for last March would have reported
     * today's rent. It now reads the schedule row **in force on that date**, through the very same
     * `ChargeScheduleService::pickInForce()` the billing engine and the writer use — a rent roll
     * that decided "current rent" by its own rule would eventually disagree with what actually
     * bills, which is the one thing a rent roll may not do.
     *
     * Charges, units, options and deposits are eager-loaded and resolved in memory: a per-lease
     * query for each would be four round trips per row on a report that is meant to open instantly
     * for a whole mall.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function rentRoll(?CarbonImmutable $asOf = null, ?int $assetId = null): Collection
    {
        $asOf = ($asOf ?? CarbonImmutable::now())->startOfDay();

        $leases = TenantScope::applyTo(Lease::query(), 'unit')
            // Live tenancies as at the date: commenced, not yet ended. A rent roll is a snapshot of
            // what the mall is contracted to earn on that day, so a lease that had not started or
            // had already ended is not on it.
            ->whereIn('status', ['active', 'renewed', 'expired', 'terminated'])
            ->whereDate('commencement_date', '<=', $asOf->toDateString())
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', $asOf->toDateString()))
            ->when($assetId, fn ($q) => $q->whereHas('unit', fn ($u) => $u->where('asset_id', $assetId)))
            ->with(['tenant', 'unit', 'units', 'charges', 'options'])
            ->get()
            // `status` narrows to leases that were live THEN, but a lease terminated before the
            // as-of date still carries dates spanning it; the authority on "was this live" is the
            // lease's own predicate, so use it rather than re-deriving one here.
            ->filter(fn (Lease $lease) => $lease->status === 'active'
                || (filled($lease->expiry_date) && CarbonImmutable::instance($lease->expiry_date)->gte($asOf)));

        return $leases->map(function (Lease $lease) use ($asOf): array {
            $charges = $lease->charges;
            $amount = fn (string $type): float => (float) (
                ChargeScheduleService::pickInForce($charges->where('type', $type), $asOf)?->amount ?? 0
            );

            $rent = $amount('base_rent');
            $service = $amount('service_charge');
            $marketing = $amount('marketing');
            $area = $lease->totalAreaSqm();

            $nextStep = ChargeScheduleService::nextStepAfter($charges->where('type', 'base_rent'), $asOf);

            // The soonest unresolved option deadline — the date this tenancy needs a decision by.
            $nextOption = $lease->options
                ->where('status', 'open')
                ->filter(fn ($o) => $o->latest_notice_date)
                ->sortBy(fn ($o) => $o->latest_notice_date->timestamp)
                ->first();

            return [
                'lease_id' => $lease->id,
                'reference' => $lease->reference,
                'tenant' => $lease->tenant?->name,
                'unit' => $lease->unit?->code,
                'units' => $lease->units->pluck('code')->implode(', ') ?: $lease->unit?->code,
                'area_sqm' => $area,
                'commencement_date' => $lease->commencement_date,
                'expiry_date' => $lease->expiry_date,
                'months_remaining' => filled($lease->expiry_date)
                    ? max(0, (int) $asOf->diffInMonths(CarbonImmutable::instance($lease->expiry_date), false))
                    : null,
                'base_rent' => $rent,
                // The comparison number for any commercial portfolio. Null rather than a division
                // by zero when the unit has no recorded area.
                'rent_per_sqm_year' => $area > 0 ? round($rent * 12 / $area, 2) : null,
                'service_charge' => $service,
                'marketing' => $marketing,
                'total_monthly' => round($rent + $service + $marketing, 2),
                'escalation_rate' => $lease->escalation_type === 'fixed_percent'
                    ? (float) $lease->escalation_rate
                    : null,
                'next_step_date' => $nextStep?->start_date,
                'next_step_amount' => $nextStep ? (float) $nextStep->amount : null,
                'next_option_date' => $nextOption?->latest_notice_date,
                'next_option_type' => $nextOption?->type,
                'security_deposit' => (float) $lease->security_deposit,
                'status' => $lease->status,
            ];
        })->sortBy('unit')->values();
    }

    /**
     * Open, aged-in receivables as at a date — the ONE query every aging view starts from.
     *
     * @return Collection<int, Invoice>
     */
    private function openInvoicesAsOf(CarbonImmutable $asOf): Collection
    {
        return TenantScope::applyTo(Invoice::query(), 'lease.unit')
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->where('balance', '>', 0)
            // The same inclusion cutoff for every view, so a drill-down can never surface an
            // invoice its own summary bucket did not count.
            ->whereDate('issue_date', '<=', $asOf)
            ->with(['tenant', 'lease.unit'])
            ->get();
    }

    /**
     * Whole days an invoice is overdue as at a date. Negative/zero = not yet due.
     *
     * Floored to start-of-day on BOTH sides: `due_date` is a date (00:00) while `$asOf` carries a
     * time, so a raw diffInDays returns N.99… and every whole-day boundary ages one bucket too far
     * — an invoice 30 days overdue fell into 31–60, one due *today* into 1–30.
     */
    public static function daysOverdue(Invoice $invoice, CarbonImmutable $asOf): int
    {
        return (int) ($invoice->due_date?->startOfDay()->diffInDays($asOf->startOfDay(), false) ?? 0);
    }

    /**
     * Which aging bucket an invoice falls in as at a date.
     *
     * **One definition, every caller.** The summary, the drill-down and the collections worklist
     * all route through this — the boundary arithmetic used to be copied between the first two
     * with a comment asking them to stay identical, which is a promise a comment cannot keep. A
     * third copy for the worklist would have been the one that finally disagreed, and a bucket
     * total that disagrees with the list behind it destroys the operator's trust in both.
     */
    public static function agingBucketKey(Invoice $invoice, CarbonImmutable $asOf): string
    {
        $days = self::daysOverdue($invoice, $asOf);

        return match (true) {
            $days <= 0 => 'current',
            $days <= 30 => 'd_1_30',
            $days <= 60 => 'd_31_60',
            $days <= 90 => 'd_61_90',
            default => 'd_90_plus',
        };
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
     *
     * @return array<string,float>
     */
    private function revenueByType(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $query = InvoiceItem::query()
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
        } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
            // "All Properties" for a RESTRICTED user — pin to their assigned set (else
            // this leaked every mall's revenue; mirrors TenantScope::applyTo's fallback).
            $query->whereExists(function ($q) use ($ids) {
                $q->select(\DB::raw(1))
                    ->from('leases')
                    ->join('units', 'units.id', '=', 'leases.unit_id')
                    ->whereColumn('leases.id', 'invoices.lease_id')
                    ->whereIn('units.asset_id', $ids);
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
    private function scopedCreditNotes(): Builder
    {
        $query = CreditNote::query();

        if ($assetId = TenantScope::currentAssetId()) {
            $query->where(function ($q) use ($assetId) {
                $q->whereNull('lease_id')
                    ->orWhereHas('lease.unit', fn ($q2) => $q2->where('asset_id', $assetId));
            });
        } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
            // "All Properties" for a RESTRICTED user — pin lease-linked notes to their
            // assigned set (standalone notes stay portfolio-visible, per the resource).
            $query->where(function ($q) use ($ids) {
                $q->whereNull('lease_id')
                    ->orWhereHas('lease.unit', fn ($q2) => $q2->whereIn('asset_id', $ids));
            });
        }

        return $query;
    }
}
