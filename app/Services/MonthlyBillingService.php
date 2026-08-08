<?php

namespace App\Services;

use App\Mail\InvoiceIssued;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Support\OpsLog;
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
        $result = Cache::lock('billing:run:' . $period->format('Y-m'), 900)
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
        $result = Cache::lock('billing:run:' . $period->format('Y-m'), 900)
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
            return ['status' => 'skipped', 'reason' => 'no_applicable_charges', 'invoice' => null];
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
            $tenant->notifyPortal(new \App\Notifications\InvoiceIssuedNotification($invoice));
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

        $invoice = Invoice::create([
            'lease_id' => $lease->id,
            'tenant_id' => $lease->tenant_id,
            'status' => 'issued',
            'issue_date' => $plan['issue_date'],
            'due_date' => $plan['due_date'],
            'period_start' => $plan['period_start'],
            'period_end' => $plan['period_end'],
            'subtotal' => $plan['subtotal'],
            'vat_amount' => $plan['vat_amount'],
            'total' => $plan['total'],
            'paid_amount' => 0,
            'balance' => $plan['total'],
            'currency' => $lease->currency ?? 'EGP',
        ]);

        foreach ($plan['items'] as $item) {
            InvoiceItem::create($item + ['invoice_id' => $invoice->id]);
        }

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
            // final (partial) cycle bills its whole final month in full, matching monthly end-of-term.
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

        $applicableCharges = $lease->charges->filter(
            fn (Charge $c) => $this->chargeAppliesToPeriod($c, $periodStart, $periodEnd)
        );

        if ($applicableCharges->isEmpty()) {
            return $nothing('no_applicable_charges');
        }

        $this->assertScheduleUnambiguous($lease, $applicableCharges, $periodStart);

        // Pro-rate only if the lease commences mid-period and the caller asked for it.
        $factor = 1.0;
        $effectivePeriodStart = $periodStart;
        $commencement = $lease->commencement_date
            ? CarbonImmutable::instance($lease->commencement_date)
            : null;

        // Pro-rate the COMMENCEMENT month only (the first month of the cycle), when the cycle-start
        // month IS the commencement month. Day-count is on that month, NOT the whole cycle — for a
        // quarterly lease we prorate the partial first month and bill the rest of the cycle in full.
        if ($prorate && $commencement
            && $commencement->year === $periodStart->year && $commencement->month === $periodStart->month
            && $commencement->greaterThan($periodStart)) {
            // Sign-safe, day-granular inclusive counting. Carbon 3's diffInDays is
            // SIGNED + fractional, so the old `$periodEnd->diffInDays($start) + 1`
            // added 1 to a negative magnitude and undercharged every mid-month
            // move-in (and billed 0 for a last-day commencement). Count whole days
            // on day boundaries instead.
            $monthEnd = $periodStart->endOfMonth();
            $daysInPeriod = $periodStart->daysInMonth;
            $daysBilled = (int) abs($monthEnd->startOfDay()->diffInDays($commencement->startOfDay())) + 1;
            // Full-precision ratio — the per-line AMOUNT is rounded to 2dp, not
            // the factor, so a clean fraction (1 of 30 days) bills exactly (1000,
            // not 999 from a 4dp-rounded factor).
            $factor = $daysBilled / $daysInPeriod;
            $effectivePeriodStart = $commencement;
        }

        $items = $applicableCharges->map(function (Charge $charge) use ($periodStart, $periodEnd, $factor, $cycleMonths) {
            // Recurring (monthly) charges bill for every month in the cycle; the commencement month
            // is prorated when applicable → multiplier = factor + (the remaining full months). For a
            // monthly lease (cycleMonths = 1) this is just `factor`, unchanged. A non-monthly charge
            // (a one-off) bills once at its full amount, never multiplied.
            $multiplier = $charge->frequency === 'monthly' ? ($factor + ($cycleMonths - 1)) : 1.0;
            $amount = round((float) $charge->amount * $multiplier, 2);
            $vatRate = $charge->vat_applicable ? (float) $charge->vat_rate : 0.0;
            $vatAmount = round($amount * ($vatRate / 100), 2);
            $label = $charge->name . ' - ' . ($cycleMonths > 1
                ? $this->cycleLabel($periodStart, $periodEnd)
                : $periodStart->format('F Y'));
            if ($factor < 1) {
                $label .= ' (' . round($factor * 100) . '% pro-rated)';
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
        });

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
        $dueDate = $dueBasis->addDays($lease->payment_terms_days ?? 7);

        return [
            'billable' => true,
            'reason' => null,
            'period_start' => $effectivePeriodStart,
            'period_end' => $periodEnd,
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
     * @param  \Illuminate\Support\Collection<int, Charge>  $applicable
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
            ->whereDate('period_start', '<=', $periodEnd->toDateString())
            ->whereDate('period_end', '>=', $periodStart->toDateString())
            // 'utility' joins the list for the same reason as percentage_rent/cam_*: a utility RECHARGE
            // invoice is dated to the CONSUMPTION month, which overlaps this probe's window — without
            // the exclusion a recharged month would read as "already billed" and the monthly run would
            // silently skip that lease's BASE RENT (the revenue-leak class fixed for % rent).
            // 'violation_fine' joins for the same reason: a standalone fine invoice is dated to the
            // violation's month and would otherwise suppress that lease's base rent.
            ->whereDoesntHave('items', fn ($q) => $q->whereIn('type', ['percentage_rent', 'cam_recovery', 'cam_admin_fee', 'utility', 'violation_fine']))
            ->exists();
    }

    /** Human label for a multi-month cycle, e.g. "Feb–Apr 2026" or "Nov 2026 – Jan 2027". */
    private function cycleLabel(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): string
    {
        return $periodStart->year === $periodEnd->year
            ? $periodStart->format('M') . '–' . $periodEnd->format('M Y')
            : $periodStart->format('M Y') . ' – ' . $periodEnd->format('M Y');
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
