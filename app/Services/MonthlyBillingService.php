<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Lease;
use App\Notifications\InvoiceIssuedNotification;
use App\Support\OpsLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class MonthlyBillingService
{
    /**
     * Generate invoices for every active lease for the given month.
     *
     * Idempotent: a lease that already has an invoice covering $period is skipped.
     * Each lease is processed in its own transaction so one failure does not abort the run.
     *
     * @return array{period:string, leases_considered:int, created:int, skipped:int, failed:int, failed_lease_ids:int[]}
     */
    public function runForPeriod(?CarbonImmutable $period = null, ?int $assetId = null): array
    {
        $period = ($period ?? CarbonImmutable::now())->startOfMonth();

        // Serialise concurrent runs for the same period so a manual CLI run can't
        // race the scheduled job and double-bill. (The queued job also carries
        // WithoutOverlapping; this lock also covers the synchronous path.)
        //
        // The lock key is deliberately NOT scoped by asset: a property-scoped run must still
        // serialise against the portfolio-wide scheduled job, whose lease set contains its own.
        $result = Cache::lock('billing:run:'.$period->format('Y-m'), 900)
            ->get(fn () => $this->billForPeriod($period, $assetId));

        if ($result === false) {
            OpsLog::warning('Monthly billing skipped — a run for this period is already in progress', ['period' => $period->format('Y-m')]);

            return ['period' => $period->format('Y-m'), 'leases_considered' => 0, 'created' => 0, 'skipped' => 0, 'failed' => 0, 'failed_lease_ids' => []];
        }

        return $result;
    }

    private function billForPeriod(CarbonImmutable $period, ?int $assetId = null): array
    {
        $periodStart = $period;
        $periodEnd = $period->endOfMonth();

        $stats = [
            'period' => $period->format('Y-m'),
            'leases_considered' => 0,
            'created' => 0,
            'skipped' => 0,
            'failed' => 0,
            'failed_lease_ids' => [],
        ];

        Lease::query()
            // The one definition of "which leases bill this period" — mirrored by
            // Lease::isBillableForPeriod(), which the manual path checks. See that docblock.
            ->billableForPeriod($periodStart, $periodEnd)
            // Optional property scope. Null = portfolio-wide, which is what the scheduled job and
            // the CLI pass (unchanged). The admin preview passes the property the operator is in,
            // so a manual post bills the mall they are looking at and not the one next door.
            ->when($assetId, fn ($q) => $q->whereHas('unit', fn ($u) => $u->where('asset_id', $assetId)))
            ->with(['charges' => fn ($q) => $q->where('is_active', true)])
            ->chunkById(100, function ($leases) use (&$stats, $periodStart, $periodEnd) {
                foreach ($leases as $lease) {
                    $stats['leases_considered']++;

                    if ($this->alreadyBilledForMonth($lease, $periodStart, $periodEnd)) {
                        $stats['skipped']++;

                        continue;
                    }

                    try {
                        $invoice = DB::transaction(function () use ($lease, $periodStart, $periodEnd) {
                            // Prorate the commencement month. Until 2026-08-08 the bulk run passed
                            // the default `false` here, so the ONLY path that ever prorated was the
                            // manual per-lease "Generate Invoice" action — meaning every mid-month
                            // move-in the scheduled run reached first was billed a FULL month, and
                            // the overcharge landed on exactly the leases nobody was watching.
                            //
                            // This is not a behaviour change for a normal lease: generateInvoiceForLease
                            // only applies a factor when the commencement falls strictly INSIDE the
                            // period, so a lease commencing on the 1st (and every lease past its
                            // first month) still bills at 1.0.
                            return $this->generateInvoiceForLease($lease, $periodStart, $periodEnd, prorate: true);
                        });
                        // A null invoice = the lease had no applicable charges this period (e.g. all
                        // charges inactive, or only quarterly/annual charges in an off-month). Count it
                        // as SKIPPED, not created — otherwise the run summary hides a silently-unbilled
                        // lease (matches the single-lease generateForLease 'no_applicable_charges' path).
                        if ($invoice) {
                            $stats['created']++;
                            $this->notifyInvoiceIssued($invoice);
                        } else {
                            $stats['skipped']++;
                        }
                    } catch (Throwable $e) {
                        $stats['failed']++;
                        $stats['failed_lease_ids'][] = $lease->id;
                        OpsLog::error('Monthly billing failed for lease', [
                            'lease_id' => $lease->id,
                            'period' => $periodStart->format('Y-m'),
                            'exception' => $e,
                        ]);
                    }
                }
            });

        // Run-level summary so a partial failure (e.g. 5 of 500 leases) is
        // visible without trawling per-lease error lines.
        OpsLog::info('Monthly billing run complete', collect($stats)->except('failed_lease_ids')->all());
        if ($stats['failed'] > 0) {
            OpsLog::warning('Monthly billing run had failures', [
                'period' => $stats['period'],
                'failed' => $stats['failed'],
                'failed_lease_ids' => $stats['failed_lease_ids'],
            ]);
        }

        return $stats;
    }

    /**
     * Generate a single invoice for one lease for a given period.
     *
     * Idempotent (same skip-rule as runForPeriod). Returns a status array so
     * the UI can render a friendly notification.
     *
     * @return array{status:'created'|'skipped'|'failed', reason?:string, invoice:?Invoice}
     */
    public function generateForLease(Lease $lease, ?CarbonImmutable $period = null, bool $prorate = false): array
    {
        $period = ($period ?? CarbonImmutable::now())->startOfMonth();

        // Contend on the SAME period lock the bulk run holds (runForPeriod). The manual
        // "Generate Invoice" action is otherwise unserialised, and idempotency here is a
        // check-then-create with no DB unique key — so a double-click, a second admin, or a
        // manual generate racing the scheduled run could each pass the alreadyBilled probe
        // and mint a duplicate invoice for the same lease-month. The lock is the real guard.
        $result = Cache::lock('billing:run:'.$period->format('Y-m'), 900)
            ->get(fn () => $this->generateForLeaseUnderLock($lease, $period, $prorate));

        if ($result === false) {
            OpsLog::warning('Single-lease invoice generation skipped — a billing run for this period is in progress', [
                'lease_id' => $lease->id,
                'period' => $period->format('Y-m'),
            ]);

            return ['status' => 'skipped', 'reason' => 'run_in_progress', 'invoice' => null];
        }

        return $result;
    }

    /** @return array{status:'created'|'skipped'|'failed', reason?:string, invoice:?Invoice} */
    private function generateForLeaseUnderLock(Lease $lease, CarbonImmutable $period, bool $prorate): array
    {
        $periodStart = $period;
        $periodEnd = $period->endOfMonth();

        // The scheduled run filters eligibility in its QUERY; this path is handed a lease the
        // operator picked, so it had no filter and applied none of those rules. Measured: it
        // created a real AR invoice — which posts to the GL — for a terminated lease, a draft
        // lease, and a lease two months past its expiry, all of which the batch run refused.
        if (! $lease->isBillableForPeriod($periodStart, $periodEnd)) {
            return ['status' => 'skipped', 'reason' => 'lease_not_billable', 'invoice' => null];
        }

        if ($this->alreadyBilledForMonth($lease, $periodStart, $periodEnd)) {
            return ['status' => 'skipped', 'reason' => 'already_billed', 'invoice' => null];
        }

        // Fit-out / rent-free grace — nothing bills this period. Distinct reason so the UI can
        // say "this lease is in its fit-out period" rather than a misleading "no charges".
        if ($lease->periodInFitOut($periodEnd)) {
            return ['status' => 'skipped', 'reason' => 'fit_out', 'invoice' => null];
        }

        // Off-cycle month for a quarterly/annual lease — this month is covered by (or belongs to)
        // a multi-month cycle whose start is a different month. Distinct reason so the UI can tell
        // the operator to pick the cycle-start month rather than showing a misleading "no charges".
        if ($lease->billingCycleMonths() > 1 && ! $lease->isBillingCycleStart($periodStart)) {
            return ['status' => 'skipped', 'reason' => 'off_cycle', 'invoice' => null];
        }

        $lease->loadMissing(['charges' => fn ($q) => $q->where('is_active', true)]);

        try {
            $invoice = DB::transaction(
                fn () => $this->generateInvoiceForLease($lease, $periodStart, $periodEnd, $prorate)
            );
        } catch (Throwable $e) {
            OpsLog::error('Single-lease invoice generation failed', [
                'lease_id' => $lease->id,
                'period' => $periodStart->format('Y-m'),
                'exception' => $e,
            ]);

            return ['status' => 'failed', 'reason' => 'exception', 'invoice' => null];
        }

        if (! $invoice) {
            // Report the plan's OWN reason rather than flattening every empty invoice to
            // "no applicable charges". Since the decision was extracted into planInvoiceForLease()
            // the real reason is knowable — `fit_out` when a net-abated lease's every charge is
            // abated, for instance — and "in fit-out grace" is an answer where "no charges" is a
            // riddle. Re-planning a lease that produced nothing is a read; it writes nothing.
            $reason = $this->planInvoiceForLease($lease, $period, $period->endOfMonth(), $prorate)['reason']
                ?? 'no_applicable_charges';

            return ['status' => 'skipped', 'reason' => $reason, 'invoice' => null];
        }

        $this->notifyInvoiceIssued($invoice);

        return ['status' => 'created', 'invoice' => $invoice];
    }

    private function notifyInvoiceIssued(Invoice $invoice): void
    {
        $tenant = $invoice->tenant;
        if (! $tenant) {
            return;
        }

        try {
            // Notification ships via 'mail' + 'database' so the tenant gets
            // both an email with PDF attachment and a portal bell entry.
            $tenant->notifyPortal(new InvoiceIssuedNotification($invoice));
        } catch (Throwable $e) {
            OpsLog::warning('Invoice issued notification failed to queue', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function generateInvoiceForLease(Lease $lease, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, bool $prorate = false): ?Invoice
    {
        $plan = $this->planInvoiceForLease($lease, $periodStart, $periodEnd, $prorate);

        if (! $plan['billable']) {
            return null;
        }

        // The plan's own subtotal/vat_amount/total are not passed: `IssueInvoiceService` derives
        // them from the same lines by the same rule, so passing them would be two statements of one
        // number. The preview still reads them off the plan — it renders without writing anything.
        $invoice = app(IssueInvoiceService::class)->issue(
            agreement: $lease,
            items: $plan['items'],
            issueDate: $plan['issue_date'],
            periodStart: $plan['period_start'],
            periodEnd: $plan['period_end'],
            // Passed rather than defaulted: the monthly run anchors the due date to the later of
            // the issue date and today, so a back-filled or off-the-1st run is not born overdue.
            dueDate: $plan['due_date'],
        );

        // The marketing levy is now a real line item (charged to the tenant) and
        // funds the property's marketing budget via InvoiceItem's saved hook
        // (MarketingBudget::recomputeAccrued) — derived from source, not incremented.

        return $invoice;
    }

    /**
     * Decide what this lease WOULD be billed for the period, and compute every line — without
     * writing anything.
     *
     * This is the whole of the billing decision: fit-out grace, the billing cycle, which charges
     * apply, proration, the line amounts, VAT, the header totals and the dates. `generateInvoiceForLease()`
     * persists its output verbatim and `previewForPeriod()` renders it. **That shared path is the
     * point** — a preview computed by a second implementation is a preview that can lie, and the one
     * thing an operator must be able to trust about a dry run is that it is the run.
     *
     * `reason` mirrors the skip reasons the single-lease path already returns (`fit_out`,
     * `off_cycle`, `no_applicable_charges`) so the UI can say *why* nothing bills rather than
     * showing an unexplained blank.
     *
     * @return array{billable:bool, reason:?string, period_start:CarbonImmutable, period_end:CarbonImmutable, issue_date:?CarbonImmutable, due_date:?CarbonImmutable, factor:float, cycle_months:int, items:array<int,array<string,mixed>>, subtotal:float, vat_amount:float, total:float}
     */
    /**
     * The window a charge COVERS on an invoice for `[$periodStart, $periodEnd]` — EG-30 (M-2).
     *
     * An advance row covers the invoice's own period, which is what every row did before this and
     * what a null `billing_timing` still means. An arrears row covers the cycle BEFORE it: the
     * September invoice carries August's service charge, because September's is not knowable until
     * September has run.
     *
     * Shifted by whole CYCLES, not by one month, so a quarterly lease's arrears row covers the
     * previous quarter rather than a month that sits inside the current one. `subMonthsNoOverflow`
     * for the reason `RentEscalationService` uses its counterpart: Carbon's plain `subMonths()`
     * overflows a month-end date into the neighbouring month, which would silently move the window
     * a charge is billed for.
     *
     * `$isFinalCycle` makes the last invoice settle the arrears window AND its own period — see the
     * body for why, because without it the final month of every arrears charge was never billed at
     * all.
     *
     * Static and pure so `previewForPeriod()` and the forecast can ask the same question without
     * re-deriving it — two answers to "what does this line cover" is how a tenant comes to be
     * billed for a month twice.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function coveredWindow(
        Charge $charge,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        int $cycleMonths,
        bool $isFinalCycle = false,
    ): array {
        if (! $charge->billsInArrears()) {
            return [$periodStart, $periodEnd];
        }

        $shift = max($cycleMonths, 1);
        $from = $periodStart->subMonthsNoOverflow($shift)->startOfMonth();

        // THE LAST INVOICE SETTLES EVERYTHING OUTSTANDING, and without this the final month of
        // every arrears charge was silently never billed.
        //
        // An arrears row is billed one invoice late by design, so the final month would need an
        // invoice dated after the lease ended — and there is none. `Lease::scopeBillableForPeriod()`
        // requires `expiry_date >= period_start`, so a lease expiring 31 August is not selected for
        // the September run at all, and `leases:expire` has moved its status off `active` by then
        // anyway. The service charge for August would simply never appear, on every arrears lease,
        // with nothing in the run summary to say so — the absence-failure class this codebase has
        // been bitten by repeatedly.
        //
        // So on the last billable cycle the window runs from the usual arrears start THROUGH the
        // end of this period, settling both. That is what an operator does by hand when a tenant
        // leaves, and it keeps the fix inside the planner rather than widening the lease-selection
        // scope, which four other callers share.
        if ($isFinalCycle) {
            return [$from, $periodEnd];
        }

        return [$from, $from->addMonthsNoOverflow($shift - 1)->endOfMonth()];
    }

    /**
     * How many whole months a window covers, counting a partial month as its day-share.
     *
     * **The one definition of "how much of a period does this lease actually run".** The billing
     * multiplier is this; so is the earned/unearned split when a termination credits back a month
     * already billed (`CreditUnearnedBillingService`). Two copies of this rule would let a credit
     * disagree with the invoice it credits, by a day or two, on every mid-month move-out — a
     * difference nobody would notice and everybody would have to reconcile.
     *
     * A full month contributes 1, a partial one its days ÷ that month's length, a month the window
     * does not reach contributes 0. Day-share per MONTH rather than across the whole window,
     * because a quarter's months are 30, 31 and 31 days and rent is a monthly amount.
     *
     * @param  CarbonImmutable  $cycleStart  first month of the cycle
     * @param  int  $cycleMonths  how many months the cycle spans
     * @param  CarbonImmutable  $windowStart  first day the lease runs inside it
     * @param  CarbonImmutable  $windowEnd  last day the lease runs inside it
     */
    public static function monthsCovered(
        CarbonImmutable $cycleStart,
        int $cycleMonths,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): float {
        $total = 0.0;

        for ($i = 0; $i < max($cycleMonths, 1); $i++) {
            $month = $cycleStart->addMonths($i);
            $monthStart = $month->startOfMonth();
            $monthEnd = $month->endOfMonth();

            $from = $windowStart->greaterThan($monthStart) ? $windowStart : $monthStart;
            $to = $windowEnd->lessThan($monthEnd) ? $windowEnd : $monthEnd;

            if ($to->lessThan($from)) {
                continue;   // the lease does not run at all in this month
            }

            // Sign-safe, day-granular inclusive counting. Carbon 3's `diffInDays` is SIGNED and
            // fractional, so the old `$end->diffInDays($start) + 1` added 1 to a negative magnitude
            // — it undercharged every mid-month move-in and billed 0 for a last-day commencement.
            // Count whole days on day boundaries instead.
            $days = (int) abs($to->startOfDay()->diffInDays($from->startOfDay())) + 1;

            // Full-precision ratio: the per-line AMOUNT is rounded to 2dp, never the factor, so a
            // clean fraction (1 of 30 days) bills exactly 1000 rather than 999.
            $total += $days / $month->daysInMonth;
        }

        return $total;
    }

    public function planInvoiceForLease(Lease $lease, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, bool $prorate = false): array
    {
        $nothing = fn (string $reason): array => [
            'billable' => false,
            'reason' => $reason,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'issue_date' => null,
            'due_date' => null,
            'factor' => 1.0,
            'cycle_months' => $lease->billingCycleMonths(),
            'items' => [],
            'subtotal' => 0.0,
            'vat_amount' => 0.0,
            'total' => 0.0,
        ];

        // Fit-out / rent-free grace: suppress the ENTIRE invoice for periods inside the grace
        // window (operator decision 2026-07-19, OPEN-QUESTIONS C1.5 = full grace on all charges).
        if ($lease->periodInFitOut($periodEnd)) {
            return $nothing('fit_out');
        }

        // Billing cadence (operator decision 2026-07-19): a quarterly/annual lease bills only on a
        // cycle-START month, and one invoice covers the WHOLE cycle in advance. On a mid-cycle month
        // it bills nothing (the cycle-start invoice already covers it). Cycles are anchored to the
        // lease's first billable month; every cycle is a full N months (no partial cycles). A monthly
        // lease has cycleMonths = 1, so this is a no-op for it.
        $cycleMonths = $lease->billingCycleMonths();
        if ($cycleMonths > 1) {
            if (! $lease->isBillingCycleStart($periodStart)) {
                return $nothing('off_cycle');
            }
            $cycleEnd = $periodStart->addMonths($cycleMonths - 1)->endOfMonth();
            // Never bill whole months AFTER the lease has ended. Cap the cycle at the expiry month,
            // then recompute how many months it actually covers so BOTH period_end and the multiplier
            // (factor + cycleMonths - 1) truncate together. Without this, a lease whose term isn't a
            // whole number of cycles gets its final cycle billed in full past expiry — worst case a
            // mid-month annual lease mints a second full-year invoice for a lease with days left. The
            // final (partial) cycle now PRORATES its final month if the lease ends mid-month, which
            // is the same rule monthly leases follow since MF-02 — the two paths agree again.
            if ($lease->expiry_date) {
                $expiryMonthEnd = CarbonImmutable::instance($lease->expiry_date)->endOfMonth();
                if ($cycleEnd->greaterThan($expiryMonthEnd)) {
                    $cycleEnd = $expiryMonthEnd;
                }
            }
            $periodEnd = $cycleEnd;
            $cycleMonths = ($periodEnd->year - $periodStart->year) * 12
                + ($periodEnd->month - $periodStart->month) + 1;
        }

        // Is this the LAST cycle this lease will ever be billed for? An arrears row on the final
        // invoice has to settle its own month too, because no later invoice will exist — see
        // `coveredWindow()`. A holdover is never final: its expiry is deliberately in the past and
        // `holdover_from` is what keeps it billing.
        $isFinalCycle = filled($lease->expiry_date)
            && ! $lease->isBillableHoldoverFor($periodEnd)
            && CarbonImmutable::instance($lease->expiry_date)->lessThanOrEqualTo($periodEnd);

        // Each charge is asked about the window IT covers, which for an arrears row is the previous
        // cycle (EG-30 / M-2). Asking every charge about the invoice's own period is what made
        // arrears unexpressible: a service charge that started on 1 September would have billed
        // September's service on the September invoice, when the whole point is that September's
        // service is not knowable until October.
        $applicableCharges = $lease->charges->filter(
            function (Charge $c) use ($periodStart, $periodEnd, $cycleMonths, $isFinalCycle) {
                [$from, $to] = self::coveredWindow($c, $periodStart, $periodEnd, $cycleMonths, $isFinalCycle);

                return $this->chargeAppliesToPeriod($c, $from, $to);
            }
        );

        if ($applicableCharges->isEmpty()) {
            return $nothing('no_applicable_charges');
        }

        // Per-charge fit-out abatement. A `gross` lease never reaches here (its whole invoice was
        // suppressed above); a `rent_only` lease bills everything EXCEPT the abated types, which is
        // the standard "rent free, service charge payable" deal Atriom previously could not express.
        $abated = $lease->abatedChargeTypesFor($periodEnd);
        if ($abated !== []) {
            $applicableCharges = $applicableCharges->reject(
                fn (Charge $c) => in_array($c->type, $abated, true)
            );

            // Everything this lease bills happens to be abated — nothing to invoice, and `fit_out`
            // is the honest reason.
            if ($applicableCharges->isEmpty()) {
                return $nothing('fit_out');
            }
        }

        $this->assertScheduleUnambiguous($lease, $applicableCharges, $periodStart);

        // The billable window inside this period: the lease's own dates clipped to it.
        $commencement = $lease->commencement_date
            ? CarbonImmutable::instance($lease->commencement_date)
            : null;

        // LEADING edge — the commencement month. Prorated only when the caller asked for it,
        // because "does a mid-month move-in pay part of the month" is a commercial term the
        // operator sets per run (the historical `$prorate` flag).
        $effectivePeriodStart = ($prorate && $commencement && $commencement->greaterThan($periodStart))
            ? $commencement
            : $periodStart;

        // TRAILING edge — the last day the lease actually runs (story MF-02, scenario S8).
        //
        // **Unconditional, unlike the leading edge.** Billing a tenant for days after their lease
        // ended is not a commercial choice, it is an error that the operator then has to unwind with
        // a manual credit note — which is exactly what S8 describes. Until this existed, proration
        // was commencement-only and a lease terminating on the 18th was billed the whole month.
        //
        // A holdover lease is exempt: its expiry is deliberately in the past and `holdover_from` is
        // what makes it billable at all, so clipping to expiry would bill it nothing (LE-04).
        $effectivePeriodEnd = $periodEnd;

        if (filled($lease->expiry_date) && ! $lease->isBillableHoldoverFor($periodEnd)) {
            $expiry = CarbonImmutable::instance($lease->expiry_date);

            if ($expiry->lessThan($periodEnd)) {
                $effectivePeriodEnd = $expiry;
            }
        }

        // The multiplier is the sum of each month's own covered fraction. One formula for both
        // edges and for a multi-month cycle: a full month contributes 1, a partial one contributes
        // its day-share, a month the lease does not reach contributes nothing. For a monthly lease
        // that never ends mid-month this is exactly 1, so nothing that bills today changes.
        $multiplier = self::monthsCovered($periodStart, $cycleMonths, $effectivePeriodStart, $effectivePeriodEnd);

        // Nothing of this period falls inside the lease — a cycle whose months are all past the
        // end date. Better to say so than to mint a zero invoice.
        if ($multiplier <= 0) {
            return $nothing('lease_ended');
        }

        // RENT-COMMENCEMENT edge — the month the rent-free period ends part-way through.
        //
        // Unconditional, for the trailing edge's reason rather than the leading one's. Whether a
        // mid-month MOVE-IN pays for part of the month is a commercial term the operator sets per
        // run; whether rent is owed before the date the contract says rent commences is not a
        // question at all. Until this existed, `rent_commencement_date = 15 April` billed the whole
        // of April: `Lease::rentCommencesOn()` normalises to the 1st (billing periods are whole
        // months, and April IS the first rent month), and its own docblock called the remaining
        // half "a proration question" — which nothing then answered. On a 100,000 rent that is
        // 46,666.67 billed for a fortnight of a rent-free period, on the first invoice a new tenant
        // ever sees.
        //
        // Kept SEPARATE from `$multiplier` because the grace is per charge type: under net
        // abatement the tenant has been paying the service charge and the levy all through fit-out,
        // and those bill the full month here. Only what the grace actually abated is clipped.
        $rentStart = $lease->rent_commencement_date
            ? CarbonImmutable::instance($lease->rent_commencement_date)
            : null;

        $graceMultiplier = ($rentStart
            && $rentStart->greaterThan($effectivePeriodStart)
            && ! $rentStart->greaterThan($effectivePeriodEnd))
                ? self::monthsCovered($periodStart, $cycleMonths, $rentStart, $effectivePeriodEnd)
                : null;

        // The share of a full cycle, for the label and the `prorated` flag.
        $factor = $multiplier / max($cycleMonths, 1);

        // The rate is resolved for the date the invoice will carry — `effectivePeriodStart`, which
        // becomes `issue_date` below and is the GL entry_date. Reading `charges.vat_rate` directly
        // meant a rate change never reached rent or service charge; see `Charge::resolvedVatRate()`.
        // The lease's OWN bounds, unclipped to this invoice's period. `$effectivePeriodStart/End`
        // above are the lease clipped INTO the period, which is the right answer for an advance row
        // and useless for an arrears one — the window it prorates against is a month the invoice's
        // period does not contain.
        $leaseWindowStart = $commencement ?? CarbonImmutable::create(1970, 1, 1);

        $leaseWindowEnd = (filled($lease->expiry_date) && ! $lease->isBillableHoldoverFor($periodEnd))
            ? CarbonImmutable::instance($lease->expiry_date)
            : CarbonImmutable::create(2999, 12, 31);

        $items = $applicableCharges->map(function (Charge $charge) use ($lease, $periodStart, $periodEnd, $multiplier, $graceMultiplier, $cycleMonths, $effectivePeriodStart, $leaseWindowStart, $leaseWindowEnd, $isFinalCycle) {
            // Recurring (monthly) charges bill the covered fraction of every month in the cycle. A
            // non-monthly charge (a one-off) bills once at its full amount, never multiplied.
            //
            // A charge the rent-free period abated is clipped to the rent-commencement date instead
            // — so on a net-abatement lease the rent bills half of April while the service charge,
            // which the tenant has been paying since handover, bills all of it.
            // The window THIS row covers — the invoice's own period, or the previous cycle for an
            // arrears row (EG-30 / M-2).
            [$coveredStart, $coveredEnd] = self::coveredWindow($charge, $periodStart, $periodEnd, $cycleMonths, $isFinalCycle);

            // Driven by the COVERED window, not by `$cycleMonths` — on a final invoice an arrears
            // row spans two months while the cycle is still one, and branching on the cycle printed
            // "July 2026" over a line that was billing July AND August.
            $coveredMonths = (($coveredEnd->year - $coveredStart->year) * 12)
                + ($coveredEnd->month - $coveredStart->month) + 1;

            $rowMultiplier = match (true) {
                $charge->frequency !== 'monthly' => 1.0,
                $graceMultiplier !== null && $lease->graceAbates($charge->type) => $graceMultiplier,
                // An arrears row prorates against the month it COVERS, not the month the invoice
                // is dated to. A lease that commenced on 15 August owes half of August's service
                // charge on the September invoice — using September's multiplier would bill a full
                // month for a half month of service, and the error is invisible because the figure
                // is plausible.
                $charge->billsInArrears() => self::monthsCovered(
                    $coveredStart,
                    // The final window spans the arrears cycle AND this one, so count both — a
                    // fixed `$cycleMonths` here would silently bill only the first of them.
                    $coveredMonths,
                    $leaseWindowStart->greaterThan($coveredStart) ? $leaseWindowStart : $coveredStart,
                    $leaseWindowEnd->lessThan($coveredEnd) ? $leaseWindowEnd : $coveredEnd,
                ),
                default => $multiplier,
            };

            // Nothing of the covered window falls inside the lease — an arrears row on the FIRST
            // invoice, where the month it would bill is before the lease existed. It bills nothing
            // now and bills normally next month, which is the honest answer rather than a zero line.
            if ($rowMultiplier <= 0) {
                return null;
            }

            $amount = round((float) $charge->amount * $rowMultiplier, 2);
            // The rate is resolved for the date the INVOICE carries, not the covered month: an
            // arrears line is originated on the invoice's own date, and that is the date the GL
            // entry and the tax point take.
            $vatRate = $charge->resolvedVatRate($effectivePeriodStart);
            $vatAmount = round($amount * ($vatRate / 100), 2);
            $label = $charge->name.' - '.($coveredMonths > 1
                ? $this->cycleLabel($coveredStart, $coveredEnd)
                : $coveredStart->format('F Y'));

            // Said on the line, because the invoice header cannot say it: this document's period is
            // September and this line is August's. A tenant reading "Service charge - August 2026"
            // under a September invoice would otherwise reasonably think it a duplicate.
            //
            // A LITERAL, not `__()`, and deliberately so. `invoice_items.description` is stored
            // prose and everything already in it is English: the `% pro-rated` suffix below is a
            // hardcoded literal and the month comes from `format('F Y')`. Translating this one
            // clause would freeze the BILLING RUN's locale into the row — a queue worker running in
            // Arabic would store an Arabic word beside an English month on the same line, and the
            // register would then hold both. Localising a stored invoice description is real work
            // and it has a known shape here (store the DATA, resolve the words at render time, as
            // `ActivityVocabulary` does). It is not this change.
            if ($charge->billsInArrears()) {
                $label .= ' (in arrears)';
            }

            // The line's OWN share, so a part-month rent line is marked pro-rated while a full-month
            // service charge beside it is not.
            $rowFactor = $rowMultiplier / max($cycleMonths, 1);

            if ($charge->frequency === 'monthly' && $rowFactor < 1) {
                $label .= ' ('.round($rowFactor * 100).'% pro-rated)';
            }

            return [
                'charge_id' => $charge->id,
                'description' => $label,
                'type' => $charge->type,
                'amount' => $amount,
                'vat_rate' => $vatRate,
                'vat_amount' => $vatAmount,
                'total' => round($amount + $vatAmount, 2),
            ];
        })->filter()->values();

        // Every line was an arrears row covering a month before the lease began — the first invoice
        // of a lease whose only charges bill behind. Nothing to raise this month; next month bills
        // normally. Saying so beats minting an empty invoice.
        if ($items->isEmpty()) {
            return $nothing('no_applicable_charges');
        }

        $subtotal = round((float) $items->sum('amount'), 2);
        $vatAmount = round((float) $items->sum('vat_amount'), 2);
        $total = round($subtotal + $vatAmount, 2);

        // Never let an invoice be born overdue. issue_date stays at the period start
        // deliberately — it is the GL entry_date (LedgerRealtimeSync::SOURCE_DATE_COLUMNS)
        // and the YYYYMM segment of the invoice number, so moving it would re-period revenue
        // and re-number the bill (a separate accounting decision). But the DUE date must
        // anchor to when the tenant can actually receive the invoice — the later of the
        // issue date and today — plus the payment terms. Otherwise a late / back-filled /
        // off-the-1st run (e.g. a mid-month "Generate Invoice", or monthly_billing_day > 1)
        // dates the bill to the 1st and derives a due date already in the past, so that
        // night's overdue-scan duns the owner and LateFeeService penalises a same-day bill.
        $issueDate = $effectivePeriodStart;
        $today = CarbonImmutable::now()->startOfDay();
        $dueBasis = $issueDate->greaterThan($today) ? $issueDate : $today;
        $dueDate = $dueBasis->addDays($lease->paymentTermsDays());

        return [
            'billable' => true,
            'reason' => null,
            'period_start' => $effectivePeriodStart,
            // The window actually billed, which is not the calendar period when the lease ends
            // inside it (MF-02). A final invoice that claims to cover a whole month it prorated
            // is the document an auditor queries.
            'period_end' => $effectivePeriodEnd,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'factor' => $factor,
            'cycle_months' => $cycleMonths,
            'items' => $items->all(),
            'subtotal' => $subtotal,
            'vat_amount' => $vatAmount,
            'total' => $total,
        ];
    }

    /**
     * A DRY RUN of runForPeriod(): what would bill, what would be skipped, and why — writing nothing.
     *
     * Every row comes from the same `planInvoiceForLease()` the real run persists, and the lease set
     * comes from the same `billableForPeriod()` scope, so the preview cannot disagree with the run.
     * The already-billed probe is the same one too, which is what lets a re-preview after posting
     * show every lease as `already_billed` rather than proposing the invoices a second time.
     *
     * `$assetId` scopes to one property. The scheduled job and the CLI pass null (portfolio-wide,
     * unchanged); the admin preview passes the property the operator is in, because posting charges
     * is a per-property act everywhere else in this system.
     *
     * @return array{period:string, rows:array<int,array<string,mixed>>, totals:array{will_bill:int,skipped:int,subtotal:float,vat_amount:float,total:float}}
     */
    public function previewForPeriod(?CarbonImmutable $period = null, ?int $assetId = null): array
    {
        $periodStart = ($period ?? CarbonImmutable::now())->startOfMonth();
        $periodEnd = $periodStart->endOfMonth();

        $rows = [];
        $totals = ['will_bill' => 0, 'skipped' => 0, 'subtotal' => 0.0, 'vat_amount' => 0.0, 'total' => 0.0];

        Lease::query()
            ->billableForPeriod($periodStart, $periodEnd)
            ->when($assetId, fn ($q) => $q->whereHas('unit', fn ($u) => $u->where('asset_id', $assetId)))
            ->with(['tenant', 'unit', 'charges' => fn ($q) => $q->where('is_active', true)])
            ->chunkById(200, function ($leases) use (&$rows, &$totals, $periodStart, $periodEnd) {
                foreach ($leases as $lease) {
                    if ($this->alreadyBilledForMonth($lease, $periodStart, $periodEnd)) {
                        $rows[] = $this->previewRow($lease, ['billable' => false, 'reason' => 'already_billed'] + $this->emptyPlan($lease, $periodStart, $periodEnd));
                        $totals['skipped']++;

                        continue;
                    }

                    // prorate: true — mirrors what billForPeriod() now passes, so a mid-month
                    // commencement previews the same prorated amount it will bill.
                    $plan = $this->planInvoiceForLease($lease, $periodStart, $periodEnd, prorate: true);
                    $rows[] = $this->previewRow($lease, $plan);

                    if ($plan['billable']) {
                        $totals['will_bill']++;
                        $totals['subtotal'] += $plan['subtotal'];
                        $totals['vat_amount'] += $plan['vat_amount'];
                        $totals['total'] += $plan['total'];
                    } else {
                        $totals['skipped']++;
                    }
                }
            });

        foreach (['subtotal', 'vat_amount', 'total'] as $key) {
            $totals[$key] = round($totals[$key], 2);
        }

        return ['period' => $periodStart->format('Y-m'), 'rows' => $rows, 'totals' => $totals];
    }

    /** @return array<string, mixed> */
    private function emptyPlan(Lease $lease, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'issue_date' => null,
            'due_date' => null,
            'factor' => 1.0,
            'cycle_months' => $lease->billingCycleMonths(),
            'items' => [],
            'subtotal' => 0.0,
            'vat_amount' => 0.0,
            'total' => 0.0,
        ];
    }

    /**
     * One preview row, flattened for the table.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function previewRow(Lease $lease, array $plan): array
    {
        return [
            'lease_id' => $lease->id,
            'lease_reference' => $lease->reference,
            'tenant_name' => $lease->tenant?->name,
            'unit_code' => $lease->unit?->code,
            'billable' => $plan['billable'],
            'reason' => $plan['reason'],
            'prorated' => $plan['billable'] && $plan['factor'] < 1,
            'factor' => $plan['factor'],
            'cycle_months' => $plan['cycle_months'],
            'period_start' => $plan['period_start'],
            'period_end' => $plan['period_end'],
            'due_date' => $plan['due_date'],
            'items' => $plan['items'],
            'line_count' => count($plan['items']),
            'subtotal' => $plan['subtotal'],
            'vat_amount' => $plan['vat_amount'],
            'total' => $plan['total'],
        ];
    }

    /**
     * Exactly ONE recurring row per charge type may cover a billing period.
     *
     * A charge type is a date-ranged schedule now (ChargeScheduleService closes one row the day
     * before the next begins), so two rows covering the same month means the schedule has an
     * OVERLAP — from a bad import, a hand-edited date, or a bug in something that writes it. The
     * old single-row world could not express that; this one can, and if it goes unnoticed the
     * tenant is billed the same rent twice on one invoice.
     *
     * Refuse loudly instead. `runForPeriod()` catches per lease, so one broken schedule is one
     * failed lease in the run summary — not a silent double charge, and not an aborted run.
     *
     * One-off charges are exempt: several genuinely can land in one month (a CAM true-up, a
     * percentage-rent overage, a utility recharge), and they are not a schedule.
     *
     * @param  Collection<int, Charge>  $applicable
     */
    private function assertScheduleUnambiguous(Lease $lease, $applicable, CarbonImmutable $periodStart): void
    {
        $clashes = $applicable
            ->filter(fn (Charge $c) => $c->frequency !== 'one_time')
            ->groupBy('type')
            ->filter(fn ($rows) => $rows->count() > 1);

        if ($clashes->isEmpty()) {
            return;
        }

        $detail = $clashes->map(fn ($rows, $type) => $type.' ('.$rows->pluck('id')->implode(', ').')')->implode('; ');

        throw new \DomainException(
            "Lease {$lease->reference} has overlapping charge-schedule rows for {$periodStart->format('F Y')}: {$detail}. "
            .'Exactly one row per charge type may cover a period — close the earlier row before the later one starts.'
        );
    }

    /**
     * Line types that raise their OWN invoice, dated into a month the recurring run also bills.
     *
     * Named because two places now reason about them, and because the list itself is the record of
     * a defect fixed five times one at a time — percentage rent, CAM recovery, the CAM admin fee, a
     * utility recharge, a violation fine, an NSF fee and a late fee each silently suppressed a
     * lease's base rent for a month before being added here.
     *
     * @var array<int, string>
     */
    private const STANDALONE_ITEM_TYPES = [
        'percentage_rent', 'cam_recovery', 'cam_admin_fee', 'utility', 'violation_fine',
        'nsf_fee', 'late_fee',
    ];

    /**
     * Has this lease already been billed a REGULAR recurring invoice covering the given month?
     * Period-OVERLAP so it also catches a multi-month (quarterly/annual) cycle invoice that spans
     * the month, and a prorated first-month invoice whose period_start is the mid-month commencement.
     *
     * It excludes the special one-off invoices that legitimately share a lease + period but are NOT
     * the regular rent invoice: the annual CAM year-end recovery (cam_recovery / cam_admin_fee items)
     * and the immediate percentage-rent overage (percentage_rent item). Neither is the lease's
     * recurring invoice, so a regular run for the month must still bill the rent. A regular invoice
     * never carries any of those item types, so this only ever skips true duplicates. (This item-type
     * test replaces the old `period_end <= monthEnd` heuristic, which a multi-month cycle invoice
     * would have slipped past → double-bill.)
     */
    private function alreadyBilledForMonth(Lease $lease, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): bool
    {
        return Invoice::where('lease_id', $lease->id)
            // A CANCELLED invoice is not "already billed" — it left the books.
            //
            // Without this, voiding a wrong invoice permanently blocked re-billing that
            // lease-month: both the bulk run and the manual action reported
            // `skipped: already_billed` forever, indistinguishable in the run summary from a lease
            // that had been billed correctly. Silent lost revenue, and the operator's only remedy
            // was to notice the missing money later.
            //
            // `written_off` is deliberately NOT excluded: that debt was rightly billed and is still
            // on the books as bad debt, so re-billing it would charge the tenant twice.
            ->whereNotIn('status', ['cancelled'])
            ->whereDate('period_start', '<=', $periodEnd->toDateString())
            ->whereDate('period_end', '>=', $periodStart->toDateString())
            // 'utility' joins the list for the same reason as percentage_rent/cam_*: a utility RECHARGE
            // invoice is dated to the CONSUMPTION month, which overlaps this probe's window — without
            // the exclusion a recharged month would read as "already billed" and the monthly run would
            // silently skip that lease's BASE RENT (the revenue-leak class fixed for % rent).
            // 'violation_fine' joins for the same reason: a standalone fine invoice is dated to the
            // violation's month and would otherwise suppress that lease's base rent.
            // 'nsf_fee' is the FOURTH instance of this class (added 2026-08-11). A bounced-cheque
            // fee invoice is dated to the current month (BillBouncedChequeFeeService), so a tenant
            // whose cheque bounced silently lost that month's rent invoice. Each of the previous
            // three was fixed one at a time; the shape is "a standalone one-off invoice dated into
            // a month the recurring run also bills".
            // 'late_fee' joins the same day, added WITH the change that made it a standalone
            // invoice (FS-27) rather than after someone lost a month's rent to it. The class is now
            // explicit: **anything that raises its own invoice dated into a billed month belongs
            // here, and belongs here in the same commit that starts raising it.**
            //
            // **The test is whether EVERY line is a one-off, not whether ANY line is.** It used to
            // be `whereDoesntHave(… whereIn …)`, which was the same thing while those types only
            // ever appeared alone. EG-30 broke that premise: an arrears UTILITY charge puts one of
            // these types on the RECURRING invoice, so the run looked at the invoice it had just
            // raised, saw a `utility` line, concluded the lease was unbilled and raised a second
            // one — double-billing the tenant on this feature's own headline example. Asking for at
            // least one NON-one-off line keeps the original intent exactly (a standalone recharge
            // has no such line and still cannot suppress the rent) and survives the mixture.
            ->where(fn ($q) => $q
                ->whereHas('items', fn ($i) => $i->whereNotIn('type', self::STANDALONE_ITEM_TYPES))
                // An invoice with no lines at all kept its old reading rather than acquiring a new
                // one here; that degenerate case is not what this change is about.
                ->orWhereDoesntHave('items'))
            ->exists();
    }

    /** Human label for a multi-month cycle, e.g. "Feb–Apr 2026" or "Nov 2026 – Jan 2027". */
    private function cycleLabel(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): string
    {
        return $periodStart->year === $periodEnd->year
            ? $periodStart->format('M').'–'.$periodEnd->format('M Y')
            : $periodStart->format('M Y').' – '.$periodEnd->format('M Y');
    }

    private function chargeAppliesToPeriod(Charge $charge, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): bool
    {
        if ($charge->start_date && $charge->start_date->greaterThan($periodEnd)) {
            return false;
        }
        if ($charge->end_date && $charge->end_date->lessThan($periodStart)) {
            return false;
        }

        return match ($charge->frequency) {
            'monthly' => true,
            // Calendar-month difference (day-of-month agnostic) so a mid-month
            // start date doesn't push the quarter a month late. diffInMonths()
            // under-counts when the period's day is earlier than the start's.
            'quarterly' => $charge->start_date
                ? ((($periodStart->year - $charge->start_date->year) * 12 + $periodStart->month - $charge->start_date->month) % 3 === 0)
                : ($periodStart->month - 1) % 3 === 0,
            'annually' => $charge->start_date
                ? $charge->start_date->month === $periodStart->month
                : $periodStart->month === 1,
            'one_time' => $charge->start_date
                && $charge->start_date->between($periodStart, $periodEnd),
            default => false,
        };
    }
}
