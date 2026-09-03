<?php

namespace App\Services\Reports;

use App\Models\Charge;
use App\Models\CreditNote;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantSalesDeclaration;
use App\Models\VendorBill;
use App\Services\ChargeScheduleService;
use App\Support\AgingBuckets;
use App\Support\CostNature;
use App\Support\InvoiceItemSettlement;
use App\Support\TenantScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only query layer that backs the Reports module. Service-layer so the
 * math can be locked by PHPUnit without spinning up a browser, and so the
 * same numbers feed PDFs, Filament tables, and (eventually) the API.
 */
class ReportService
{
    /**
     * The aging buckets, in order, and their day ranges — now `App\Support\AgingBuckets`.
     *
     * They moved because the boundaries are the OPERATOR's policy: 30/60/90 was hard-coded, so
     * "show me 45/90/120" was a deploy, and a mall whose leases pay quarterly ages nothing
     * meaningfully at 30 days.
     *
     * The move also fixed a duplication this docblock used to warn about while carrying it: the
     * ranges were here AND again as literals inside {@see agingBucketKey()}, the classifier every
     * invoice goes through. Changing one would have left the summary totals and the drill-down
     * disagreeing about which bucket an invoice is in.
     *
     * @deprecated Read `AgingBuckets::all()`; kept only so a caller mid-refactor still resolves.
     *
     * @return array<string, array{0: ?int, 1: ?int}>
     */
    public static function agingBuckets(): array
    {
        return AgingBuckets::all();
    }

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

        $invoicesInMonth = TenantScope::applyTo(Invoice::query())
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

        $paymentsInMonth = TenantScope::applyTo(Payment::query(), 'invoices')
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
            // The register, not a re-listed pair — a credit-note total on a management report must
            // count exactly what the GL counted.
            ->onTheBooks()
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

        // `with('writeOffs')`: the bucket loop below asks each invoice for its collectable figure,
        // and arrears is the one dataset that never shrinks — without the eager load that is a query
        // per open invoice, every time anyone opens AR ageing.
        $openInvoices = TenantScope::applyTo(Invoice::query())
            ->stillOwed()
            ->whereDate('issue_date', '<=', $asOf)
            ->with('writeOffs')
            ->get();

        $buckets = array_map(
            fn () => ['count' => 0, 'total' => 0.0],
            AgingBuckets::all(),
        );

        foreach ($openInvoices as $invoice) {
            $key = self::agingBucketKey($invoice, $asOf);
            $buckets[$key]['count']++;
            $buckets[$key]['total'] += $invoice->collectableBalance();
        }

        foreach ($buckets as $k => $v) {
            $buckets[$k]['total'] = round($v['total'], 2);
        }

        return $buckets;
    }

    /**
     * AR aging split by WHAT is owed, not just by how late it is (story RR-03).
     *
     * **Why this is a different report and not a column.** A headline "EGP 400k over 90 days" reads
     * as delinquent rent and prompts a collections call. If most of it is a service-charge line the
     * tenant has formally disputed, the call is the wrong action and the number is the wrong alarm.
     * Splitting the same money by charge type is what tells those two apart.
     *
     * **It re-buckets the same invoices the aging summary counts**, so the grand total ties to
     * `arAgingBuckets()` exactly. The per-type split comes from `InvoiceItemSettlement`, which
     * derives everything from `invoices.paid_amount` — so the rows sum back to the invoice balances
     * by construction rather than by a reconciliation somebody has to run.
     *
     * @return array{rows: Collection<int, array{type: string, buckets: array<string, float>, disputed: float, total: float}>, totals: array<string, float>, total: float, disputed: float}
     */
    public function arAgingByChargeType(?CarbonImmutable $asOf = null): array
    {
        $asOf = $asOf ?? CarbonImmutable::now()->endOfDay();

        $invoices = $this->openInvoicesAsOf($asOf);
        $splits = InvoiceItemSettlement::forMany($invoices);

        /** @var array<string, array<string, float>> $byType */
        $byType = [];
        /** @var array<string, float> $disputedByType */
        $disputedByType = [];

        foreach ($invoices as $invoice) {
            $bucket = self::agingBucketKey($invoice, $asOf);

            foreach ($splits[$invoice->id] ?? [] as $line) {
                if ($line['outstanding'] <= 0) {
                    continue;
                }

                $byType[$line['type']] ??= array_fill_keys(array_keys(AgingBuckets::all()), 0.0);
                $byType[$line['type']][$bucket] += $line['outstanding'];

                // Shown BESIDE the aged figure, not deducted from it (story MF-07). A disputed
                // balance is still claimed — it is simply not chargeable a late fee yet — so
                // netting it out of aging would understate what the mall is owed.
                $disputedByType[$line['type']] ??= 0.0;

                if ($line['item']->isDisputed()) {
                    $disputedByType[$line['type']] += $line['outstanding'];
                }
            }
        }

        $rows = collect($byType)
            ->map(fn (array $buckets, string $type) => [
                'type' => $type,
                'buckets' => array_map(fn (float $v) => round($v, 2), $buckets),
                'disputed' => round($disputedByType[$type] ?? 0.0, 2),
                'total' => round(array_sum($buckets), 2),
            ])
            // Biggest exposure first — the row a finance manager acts on.
            ->sortByDesc('total')
            ->values();

        $totals = array_fill_keys(array_keys(AgingBuckets::all()), 0.0);

        foreach ($rows as $row) {
            foreach ($row['buckets'] as $bucket => $amount) {
                $totals[$bucket] = round($totals[$bucket] + $amount, 2);
            }
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'total' => round((float) $rows->sum('total'), 2),
            'disputed' => round((float) $rows->sum('disputed'), 2),
        ];
    }

    /**
     * Return open invoices in a specific aging bucket, with tenant context.
     * Used by the AR Aging drilldown page.
     */
    public function arAgingDrilldown(string $bucket, ?CarbonImmutable $asOf = null): Collection
    {
        if (! array_key_exists($bucket, AgingBuckets::all())) {
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
     * @return Collection<int, array{tenant:?Tenant, tenant_id:int, total:float, buckets:array<string,float>, invoice_count:int, oldest_days:int, last_payment_at:?Carbon}>
     */
    public function arCollectionsByTenant(?CarbonImmutable $asOf = null): Collection
    {
        $asOf = $asOf ?? CarbonImmutable::now()->endOfDay();

        return $this->openInvoicesAsOf($asOf)
            ->groupBy('tenant_id')
            ->map(function (Collection $invoices, $tenantId) use ($asOf) {
                $buckets = array_fill_keys(array_keys(AgingBuckets::all()), 0.0);
                $oldest = 0;

                // COLLECTABLE, not the raw balance. `openInvoicesAsOf()` selects what is still
                // OWED; these two lines then quoted it at what was BILLED. A write-off is not a
                // settlement channel, so it deliberately leaves `balance` standing — which means
                // the collections worklist chased, and the aging buckets reported, money the
                // operator had already forgiven and the bad-debt entry had already relieved. The
                // selection was fixed at the chokepoint and the amount was not.
                foreach ($invoices as $invoice) {
                    $buckets[self::agingBucketKey($invoice, $asOf)] += $invoice->collectableBalance();
                    $oldest = max($oldest, self::daysOverdue($invoice, $asOf));
                }

                return [
                    'tenant_id' => (int) $tenantId,
                    'tenant' => $invoices->first()->tenant,
                    'total' => round((float) $invoices->sum(
                        fn (Invoice $invoice): float => $invoice->collectableBalance()
                    ), 2),
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
     * @return Collection<int, array<string, mixed>>
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
                // The rate the lease was actually SIGNED at, where it was priced that way (LS-04).
                // Shown beside the effective figure above so the two can be compared: they agree on
                // a rate-priced lease, and a gap means an abatement, a step, or a hand edit.
                'contracted_rate_per_sqm_year' => $lease->rent_pricing_basis === Lease::RENT_RATE
                    ? (float) $lease->base_rent_rate_per_sqm_year
                    : null,
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
     * How many signed leases the as-of date is holding back — the reason a roll looks incomplete.
     *
     * **Why this exists.** The rent roll is a snapshot of what the mall is contracted to earn ON a
     * day, so a lease signed today and commencing in three weeks is correctly absent from today's
     * roll. That is right, and it reads exactly like the report is broken: an operator who has just
     * activated a lease goes looking for it, searches the unit code, finds nothing, and has no way
     * to connect an empty search to a date in the subheading. Reported that way on 2026-09-01, on a
     * lease commencing in fifteen days.
     *
     * The page's `empty` state already explains the whole-table case. It cannot reach this one,
     * because the table is NOT empty — thirty-four other leases are on it and only the searched
     * one is missing.
     *
     * **Not-yet-commenced only, deliberately.** A lease that has ENDED is intuitively absent from a
     * rent roll and needs no explanation, and counting those would put a permanent line on the
     * screen of any mall with history — which is how a notice stops being read. Same rule as
     * `LedgerReportService::unallocated()`: silent when there is nothing to say, so that when it
     * does say something it is worth reading.
     *
     * Scoped exactly as `rentRoll()` scopes, or the count would describe a different set from the
     * report it annotates.
     */
    public function rentRollNotYetCommenced(?CarbonImmutable $asOf = null, ?int $assetId = null): int
    {
        $asOf = ($asOf ?? CarbonImmutable::now())->startOfDay();

        return TenantScope::applyTo(Lease::query(), 'unit')
            ->whereIn('status', ['active', 'renewed'])
            ->whereDate('commencement_date', '>', $asOf->toDateString())
            ->when($assetId, fn ($q) => $q->whereHas('unit', fn ($u) => $u->where('asset_id', $assetId)))
            ->count();
    }

    /**
     * The lease expiration schedule — what rolls off, when, and how much is at risk (story RR-02).
     *
     * **Why a leasing manager needs it and a rent roll cannot answer it.** The rent roll says what
     * the mall earns today. This says when that stops. A year with 30% of the mall's income expiring
     * is a year of negotiations that has to start eighteen months earlier, and until now the only
     * way to see one was to sort the lease table by end date and add the rents up by hand.
     *
     * **Holdovers get their own bucket rather than being counted in a past year.** A lease whose
     * term ended but which is still trading has not rolled off — its rent is live and its space is
     * occupied — and burying it under "2027" would understate both this year's risk and today's
     * income. It is the bucket a leasing manager should act on first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function expirationSchedule(?CarbonImmutable $asOf = null, ?int $assetId = null): Collection
    {
        $asOf = ($asOf ?? CarbonImmutable::now())->startOfDay();

        $leases = TenantScope::applyTo(Lease::query(), 'unit')
            ->where('status', 'active')
            ->whereDate('commencement_date', '<=', $asOf->toDateString())
            ->when($assetId, fn ($q) => $q->whereHas('unit', fn ($u) => $u->where('asset_id', $assetId)))
            ->with(['tenant', 'unit', 'units', 'charges'])
            ->get();

        $rows = $leases->map(function (Lease $lease) use ($asOf): array {
            /** @var Collection<int, Charge> $baseRent */
            $baseRent = $lease->charges->where('type', 'base_rent');
            $rent = (float) (ChargeScheduleService::pickInForce($baseRent, $asOf)?->amount ?? 0);

            $expiry = filled($lease->expiry_date) ? CarbonImmutable::instance($lease->expiry_date) : null;

            // Expired-but-trading is its own bucket, keyed so it sorts before every real year.
            $bucket = match (true) {
                $expiry === null => 'open_ended',
                $expiry->lessThan($asOf) => 'holdover',
                default => (string) $expiry->year,
            };

            return [
                'bucket' => $bucket,
                'lease_id' => $lease->id,
                'reference' => $lease->reference,
                'tenant' => $lease->tenant?->name,
                'unit' => $lease->units->pluck('code')->implode(', ') ?: $lease->unit?->code,
                'area_sqm' => $lease->totalAreaSqmOn($asOf),
                'expiry_date' => $lease->expiry_date,
                'monthly_rent' => $rent,
                'annual_rent' => round($rent * 12, 2),
                'is_holdover' => $bucket === 'holdover',
            ];
        });

        $totalArea = (float) $rows->sum('area_sqm');
        $totalAnnual = (float) $rows->sum('annual_rent');

        /** @var Collection<int, array<string, mixed>> $buckets */
        $buckets = $rows
            ->groupBy('bucket')
            ->map(fn (Collection $group, string $bucket): array => [
                'bucket' => $bucket,
                'year' => is_numeric($bucket) ? (int) $bucket : null,
                'leases' => $group->count(),
                'area_sqm' => round((float) $group->sum('area_sqm'), 2),
                'annual_rent' => round((float) $group->sum('annual_rent'), 2),
                // The number the question is really about: how much of the mall is up in this year.
                'share_of_area_pct' => $totalArea > 0 ? round($group->sum('area_sqm') / $totalArea * 100, 1) : 0.0,
                'share_of_rent_pct' => $totalAnnual > 0 ? round($group->sum('annual_rent') / $totalAnnual * 100, 1) : 0.0,
                // A plain array, not a Collection: PHPStan's Collection<TValue> is invariant, so a
                // nested one makes the whole bucket shape unassignable to array<string, mixed>.
                'rows' => $group->sortBy('expiry_date')->values()->all(),
            ])
            // Holdover first (act now), then open-ended last, years in order between.
            ->sortBy(fn (array $b) => match ($b['bucket']) {
                'holdover' => '0000',
                'open_ended' => '9999',
                default => $b['bucket'],
            })
            ->values();

        return $buckets;
    }

    /**
     * Occupancy cost as a percentage of sales, per tenant (story RR-04).
     *
     * **The number that tells a mall GM who is in trouble before they miss a payment.** A tenant
     * paying 12% of turnover in occupancy cost is healthy; one paying 30% is failing, and will
     * usually stop paying before they say so. Atriom already held every input — invoices and
     * `TenantSalesDeclaration` — and produced the number nowhere.
     *
     * **Cost is what was BILLED, not what was paid**, because occupancy cost is a burden on the
     * business regardless of whether the tenant has settled it; mixing in payment behaviour would
     * make a struggling tenant look cheaper the longer they went without paying. Cancelled and
     * written-off invoices are excluded — nobody is being asked for that money any more.
     *
     * Late fees and violation fines are excluded on purpose: they are penalties, not occupancy.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function occupancyCost(?CarbonImmutable $from = null, ?CarbonImmutable $to = null, ?int $assetId = null): Collection
    {
        $to = ($to ?? CarbonImmutable::now())->endOfMonth()->startOfDay();
        $from = ($from ?? $to->subMonths(11))->startOfMonth();

        $leases = TenantScope::applyTo(Lease::query(), 'unit')
            ->whereIn('status', ['active', 'terminated', 'expired', 'renewed'])
            ->where('has_percentage_rent', true)
            ->when($assetId, fn ($q) => $q->whereHas('unit', fn ($u) => $u->where('asset_id', $assetId)))
            ->with(['tenant', 'unit'])
            ->get();

        if ($leases->isEmpty()) {
            return collect();
        }

        $leaseIds = $leases->pluck('id');

        // Billed occupancy cost per lease. One query for all of them rather than per lease.
        $cost = InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereIn('invoices.lease_id', $leaseIds)
            ->whereNotIn('invoices.status', ['cancelled', 'written_off'])
            ->whereDate('invoices.period_start', '>=', $from->toDateString())
            ->whereDate('invoices.period_start', '<=', $to->toDateString())
            ->whereIn('invoice_items.type', self::OCCUPANCY_COST_TYPES)
            ->selectRaw('invoices.lease_id as lease_id, sum(invoice_items.amount) as billed')
            ->groupBy('invoices.lease_id')
            ->pluck('billed', 'lease_id');

        $sales = TenantSalesDeclaration::query()
            ->whereIn('lease_id', $leaseIds)
            ->whereDate('period_start', '>=', $from->toDateString())
            ->whereDate('period_start', '<=', $to->toDateString())
            ->selectRaw('lease_id, sum(declared_sales) as sales, sum(case when is_estimate then 1 else 0 end) as estimates, count(*) as months')
            ->groupBy('lease_id')
            ->get()
            ->keyBy('lease_id');

        /** @var Collection<int, array<string, mixed>> $result */
        $result = $leases->map(function (Lease $lease) use ($cost, $sales, $from, $to): array {
            $billed = round((float) ($cost[$lease->id] ?? 0), 2);
            $row = $sales->get($lease->id);
            $declared = round((float) ($row->sales ?? 0), 2);

            return [
                'lease_id' => $lease->id,
                'reference' => $lease->reference,
                'tenant' => $lease->tenant?->name,
                'unit' => $lease->unit?->code,
                'from' => $from,
                'to' => $to,
                'occupancy_cost' => $billed,
                'declared_sales' => $declared,
                // Null, never zero or infinity, when there are no sales to divide by: a tenant who
                // has declared nothing has an UNKNOWN ratio, and showing 0% would read as the
                // healthiest tenant in the mall.
                'occupancy_cost_pct' => $declared > 0 ? round($billed / $declared * 100, 2) : null,
                'months_declared' => (int) ($row->months ?? 0),
                // Estimated sales make the ratio soft, and the operator should see that rather than
                // act on a number the tenant never filed.
                'has_estimates' => (int) ($row->estimates ?? 0) > 0,
            ];
        })
            ->sortByDesc(fn (array $r) => $r['occupancy_cost_pct'] ?? -1)
            ->values();

        return $result;
    }

    /**
     * What counts as occupancy cost.
     *
     * Rent and every reimbursement the tenant pays to occupy the space. NOT `late_fee` or
     * `violation_fine` — those are penalties for behaviour, and folding them in would make a
     * tenant's occupancy look expensive because they paid late rather than because their rent is
     * high, which inverts the signal this report exists to give.
     */
    public const OCCUPANCY_COST_TYPES = [
        'base_rent', 'service_charge', 'marketing', 'percentage_rent',
        'utility', 'parking', 'cam_recovery', 'cam_admin_fee',
    ];

    /**
     * Trading performance per tenant: MTD, YTD, MAT and like-for-like growth (story RR-05).
     *
     * **MAT — moving annual total — is the number retail actually runs on.** A calendar-year figure
     * says nothing useful in March and swings wildly around Ramadan and the school year; the
     * trailing twelve months strips seasonality out, so two dates are comparable.
     *
     * **Like-for-like is the one that is easy to get wrong.** Comparing this year's MAT to last
     * year's across the whole mall counts units that did not exist a year ago, so a centre that let
     * ten new shops shows "growth" that is nothing of the kind — it is just more shops. LFL counts
     * only leases that declared sales in BOTH windows, which is the difference between measuring
     * trading and measuring letting.
     *
     * The stated rule is **declared in both windows**, not *trading every month of both*. Real
     * declaration data has gaps, and the stricter rule would silently drop most of the portfolio —
     * a metric computed over a quarter of the mall while claiming to describe it is worse than a
     * slightly looser one that says what it counted. `lfl_leases` reports how many it counted.
     *
     * @return array{
     *   as_of: CarbonImmutable,
     *   rows: Collection<int, array<string, mixed>>,
     *   mat: float, prior_mat: float, mat_growth_pct: ?float,
     *   lfl_mat: float, lfl_prior_mat: float, lfl_growth_pct: ?float, lfl_leases: int,
     *   has_estimates: bool
     * }
     */
    public function salesAnalytics(?CarbonImmutable $asOf = null, ?int $assetId = null): array
    {
        $asOf = ($asOf ?? CarbonImmutable::now())->endOfMonth()->startOfDay();

        $matFrom = $asOf->startOfMonth()->subMonths(11);
        $priorFrom = $matFrom->subYear();
        $priorTo = $matFrom->subDay();

        $leases = TenantScope::applyTo(Lease::query(), 'unit')
            ->where('has_percentage_rent', true)
            ->when($assetId, fn ($q) => $q->whereHas('unit', fn ($u) => $u->where('asset_id', $assetId)))
            ->with(['tenant', 'unit'])
            ->get();

        if ($leases->isEmpty()) {
            return [
                'as_of' => $asOf, 'rows' => collect(),
                'mat' => 0.0, 'prior_mat' => 0.0, 'mat_growth_pct' => null,
                'lfl_mat' => 0.0, 'lfl_prior_mat' => 0.0, 'lfl_growth_pct' => null, 'lfl_leases' => 0,
                'has_estimates' => false,
            ];
        }

        // One query for every window, keyed by lease — not four queries per lease.
        $declarations = TenantSalesDeclaration::query()
            ->whereIn('lease_id', $leases->pluck('id'))
            ->whereDate('period_start', '>=', $priorFrom->toDateString())
            ->whereDate('period_start', '<=', $asOf->toDateString())
            ->get(['lease_id', 'period_start', 'declared_sales', 'is_estimate'])
            ->groupBy('lease_id');

        $inWindow = fn ($rows, CarbonImmutable $from, CarbonImmutable $to) => $rows
            ->filter(function ($d) use ($from, $to) {
                $p = CarbonImmutable::instance($d->period_start);

                return $p->gte($from) && $p->lte($to);
            });

        $rows = $leases->map(function (Lease $lease) use ($declarations, $asOf, $matFrom, $priorFrom, $priorTo, $inWindow): array {
            $all = $declarations->get($lease->id, collect());

            $mtd = $inWindow($all, $asOf->startOfMonth(), $asOf);
            $ytd = $inWindow($all, $asOf->startOfYear(), $asOf);
            $mat = $inWindow($all, $matFrom, $asOf);
            $prior = $inWindow($all, $priorFrom, $priorTo);

            $matTotal = round((float) $mat->sum('declared_sales'), 2);
            $priorTotal = round((float) $prior->sum('declared_sales'), 2);

            return [
                'lease_id' => $lease->id,
                'reference' => $lease->reference,
                'tenant' => $lease->tenant?->name,
                'unit' => $lease->unit?->code,
                'mtd' => round((float) $mtd->sum('declared_sales'), 2),
                'ytd' => round((float) $ytd->sum('declared_sales'), 2),
                'mat' => $matTotal,
                'prior_mat' => $priorTotal,
                // Null, never 0%, when there is no prior year to compare against — a new tenant has
                // UNKNOWN growth, and 0% would read as flat trading.
                'mat_growth_pct' => $priorTotal > 0 ? round(($matTotal - $priorTotal) / $priorTotal * 100, 1) : null,
                'months_declared' => $mat->count(),
                // Traded in BOTH windows: the only rows that may enter the like-for-like figure.
                'lfl_eligible' => $mat->isNotEmpty() && $prior->isNotEmpty(),
                'has_estimates' => $mat->contains(fn ($d) => (bool) $d->is_estimate),
            ];
        })->sortByDesc('mat')->values();

        $lfl = $rows->where('lfl_eligible', true);
        $lflMat = round((float) $lfl->sum('mat'), 2);
        $lflPrior = round((float) $lfl->sum('prior_mat'), 2);
        $mat = round((float) $rows->sum('mat'), 2);
        $priorMat = round((float) $rows->sum('prior_mat'), 2);

        /** @var Collection<int, array<string, mixed>> $rows */
        return [
            'as_of' => $asOf,
            'rows' => $rows,
            'mat' => $mat,
            'prior_mat' => $priorMat,
            'mat_growth_pct' => $priorMat > 0 ? round(($mat - $priorMat) / $priorMat * 100, 1) : null,
            'lfl_mat' => $lflMat,
            'lfl_prior_mat' => $lflPrior,
            'lfl_growth_pct' => $lflPrior > 0 ? round(($lflMat - $lflPrior) / $lflPrior * 100, 1) : null,
            'lfl_leases' => $lfl->count(),
            'has_estimates' => $rows->contains(fn (array $r) => $r['has_estimates']),
        ];
    }

    /**
     * Open, aged-in receivables as at a date — the ONE query every aging view starts from.
     *
     * @return Collection<int, Invoice>
     */
    private function openInvoicesAsOf(CarbonImmutable $asOf): Collection
    {
        // `stillOwed()` carries BOTH halves: live (which a hand-kept status list got wrong by
        // omitting `disputed`) and COLLECTABLE — a partial write-off leaves the invoice live with
        // its whole balance standing, so ageing counted, and the worklist chased, money the operator
        // had forgiven and the bad-debt entry had already relieved. This method is the chokepoint
        // for the drill-down, the CSV and the worklist, which is why it is answered here rather than
        // at each reader.
        return TenantScope::applyTo(Invoice::query())
            ->stillOwed()
            // The same inclusion cutoff for every view, so a drill-down can never surface an
            // invoice its own summary bucket did not count.
            ->whereDate('issue_date', '<=', $asOf)
            ->with(['tenant', 'lease.unit', 'asset'])
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

        return AgingBuckets::keyFor($days);
    }

    /**
     * Top N tenants by outstanding AR. Useful for the collections team.
     *
     * @return array<int,array{tenant:Tenant, total_outstanding:float, days_overdue_avg:int, invoice_count:int}>
     */
    public function topDelinquentTenants(int $limit = 10): array
    {
        $now = CarbonImmutable::now();

        $openInvoices = TenantScope::applyTo(Invoice::query())
            ->stillOwed()
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

        // Scoped on the invoice's own property. This used to be a hand-rolled EXISTS over
        // `leases` JOIN `units`, which no owner assessment could ever satisfy — it has no lease to
        // join to, so its marketing/VAT lines vanished from the split.
        if ($assetId = TenantScope::currentAssetId()) {
            $query->where('invoices.asset_id', $assetId);
        } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
            // "All Properties" for a RESTRICTED user — pin to their assigned set (else
            // this leaked every mall's revenue; mirrors TenantScope::applyTo's fallback).
            $query->whereIn('invoices.asset_id', $ids);
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
        // Scoped through the SAME helper the resource uses, off `credit_notes.asset_id` —
        // `CreditNote` is `#[PropertyOwned]` on its own column, so this report and the list now
        // answer "whose row is this?" identically, by construction rather than by agreement.
        //
        // It used to walk `lease → unit → asset` with a `whereNull('lease_id')` branch that let a
        // lease-less note through unconditionally, justified as "standalone notes stay
        // portfolio-visible, per the resource". That justification went stale on 2026-08-15, when
        // the denormalisation gave every credit note its own property (the same phase-2a change
        // that moved `Invoice` off the `lease.unit` chain — the invoice side was migrated, this
        // read site was not). A note against an OWNER's assessment has no lease by construction,
        // so Mall A's owner credit appeared in Mall B's monthly close, inflating B's credited
        // total and understating its net, while the credit-note list correctly hid it.
        return TenantScope::applyTo(CreditNote::query());
    }
}
