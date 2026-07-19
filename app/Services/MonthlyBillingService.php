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
    public function runForPeriod(?CarbonImmutable $period = null): array
    {
        $period = ($period ?? CarbonImmutable::now())->startOfMonth();

        // Serialise concurrent runs for the same period so a manual CLI run can't
        // race the scheduled job and double-bill. (The queued job also carries
        // WithoutOverlapping; this lock also covers the synchronous path.)
        $result = Cache::lock('billing:run:' . $period->format('Y-m'), 900)
            ->get(fn () => $this->billForPeriod($period));

        if ($result === false) {
            OpsLog::warning('Monthly billing skipped — a run for this period is already in progress', ['period' => $period->format('Y-m')]);

            return ['period' => $period->format('Y-m'), 'leases_considered' => 0, 'created' => 0, 'skipped' => 0, 'failed' => 0, 'failed_lease_ids' => []];
        }

        return $result;
    }

    private function billForPeriod(CarbonImmutable $period): array
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
            ->where('status', 'active')
            ->where('commencement_date', '<=', $periodEnd)
            ->where(function ($q) use ($periodStart) {
                $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $periodStart);
            })
            ->with(['charges' => fn ($q) => $q->where('is_active', true)])
            ->chunkById(100, function ($leases) use (&$stats, $periodStart, $periodEnd) {
                foreach ($leases as $lease) {
                    $stats['leases_considered']++;

                    // Period-OVERLAP guard, not an exact month-start match: a
                    // prorated first-month invoice stores period_start = the
                    // mid-month commencement, so an exact "= month start" check
                    // would miss it and bill the month a SECOND time (full). The
                    // period must also END within the month — else an ANNUAL invoice
                    // (a CAM year-end recovery, period Jan 1–Dec 31) would satisfy this
                    // guard for January and wrongly SKIP the lease's regular rent.
                    $alreadyBilled = Invoice::where('lease_id', $lease->id)
                        ->whereDate('period_start', '>=', $periodStart->toDateString())
                        ->whereDate('period_start', '<=', $periodEnd->toDateString())
                        ->whereDate('period_end', '<=', $periodEnd->toDateString())
                        // A percentage-rent OVERAGE invoice is billed immediately at
                        // declaration-lock time, dated to its (past) sales month — it is NOT
                        // this lease's regular monthly invoice. Exclude it so a back-filled /
                        // late monthly run for that month still bills the base rent (else the
                        // month-shaped overage period trips this guard and the rent silently
                        // vanishes). Same spirit as the annual CAM recovery invoice, which the
                        // period_end clause above already excludes. A regular monthly invoice
                        // never carries a percentage_rent line, so this only skips pure overages.
                        ->whereDoesntHave('items', fn ($q) => $q->where('type', 'percentage_rent'))
                        ->exists();

                    if ($alreadyBilled) {
                        $stats['skipped']++;
                        continue;
                    }

                    try {
                        $invoice = DB::transaction(function () use ($lease, $periodStart, $periodEnd) {
                            return $this->generateInvoiceForLease($lease, $periodStart, $periodEnd);
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

        // Period-OVERLAP guard (see runForPeriod): catches a prorated first-month
        // invoice whose period_start is the mid-month commencement, so re-running
        // for that month — prorate on or off — can't double-bill.
        $alreadyBilled = Invoice::where('lease_id', $lease->id)
            ->whereDate('period_start', '>=', $periodStart->toDateString())
            ->whereDate('period_start', '<=', $periodEnd->toDateString())
            // Must also END within the month — mirrors billForPeriod's probe. Without
            // this, an ANNUAL invoice whose period_start falls in this month (a CAM
            // year-end recovery, period Jan 1–Dec 31) would satisfy the January guard
            // and wrongly SKIP the lease's regular rent.
            ->whereDate('period_end', '<=', $periodEnd->toDateString())
            // Exclude the immediate percentage-rent overage invoice (see runForPeriod) — it
            // is not this lease's regular monthly invoice, so a per-lease generate for that
            // month must still bill the base rent.
            ->whereDoesntHave('items', fn ($q) => $q->where('type', 'percentage_rent'))
            ->exists();

        if ($alreadyBilled) {
            return ['status' => 'skipped', 'reason' => 'already_billed', 'invoice' => null];
        }

        // Fit-out / rent-free grace — nothing bills this period. Distinct reason so the UI can
        // say "this lease is in its fit-out period" rather than a misleading "no charges".
        if ($lease->periodInFitOut($periodEnd)) {
            return ['status' => 'skipped', 'reason' => 'fit_out', 'invoice' => null];
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
        // Fit-out / rent-free grace: suppress the ENTIRE invoice for periods inside the grace
        // window (operator decision 2026-07-19, OPEN-QUESTIONS C1.5 = full grace on all charges).
        // Returns null → the run counts it as skipped (no applicable charges), same as an off-month.
        if ($lease->periodInFitOut($periodEnd)) {
            return null;
        }

        $applicableCharges = $lease->charges->filter(
            fn (Charge $c) => $this->chargeAppliesToPeriod($c, $periodStart, $periodEnd)
        );

        if ($applicableCharges->isEmpty()) {
            return null;
        }

        // Pro-rate only if the lease commences mid-period and the caller asked for it.
        $factor = 1.0;
        $effectivePeriodStart = $periodStart;
        $commencement = $lease->commencement_date
            ? CarbonImmutable::instance($lease->commencement_date)
            : null;

        if ($prorate && $commencement && $commencement->between($periodStart, $periodEnd) && $commencement->greaterThan($periodStart)) {
            // Sign-safe, day-granular inclusive counting. Carbon 3's diffInDays is
            // SIGNED + fractional, so the old `$periodEnd->diffInDays($start) + 1`
            // added 1 to a negative magnitude and undercharged every mid-month
            // move-in (and billed 0 for a last-day commencement). Count whole days
            // on day boundaries instead.
            $daysInPeriod = $periodStart->daysInMonth;
            $daysBilled = (int) abs($periodEnd->startOfDay()->diffInDays($commencement->startOfDay())) + 1;
            // Full-precision ratio — the per-line AMOUNT is rounded to 2dp, not
            // the factor, so a clean fraction (1 of 30 days) bills exactly (1000,
            // not 999 from a 4dp-rounded factor).
            $factor = $daysBilled / $daysInPeriod;
            $effectivePeriodStart = $commencement;
        }

        $items = $applicableCharges->map(function (Charge $charge) use ($periodStart, $factor) {
            $amount = round((float) $charge->amount * $factor, 2);
            $vatRate = $charge->vat_applicable ? (float) $charge->vat_rate : 0.0;
            $vatAmount = round($amount * ($vatRate / 100), 2);
            $label = $charge->name . ' - ' . $periodStart->format('F Y');
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

        $invoice = Invoice::create([
            'lease_id' => $lease->id,
            'tenant_id' => $lease->tenant_id,
            'status' => 'issued',
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'period_start' => $effectivePeriodStart,
            'period_end' => $periodEnd,
            'subtotal' => $subtotal,
            'vat_amount' => $vatAmount,
            'total' => $total,
            'paid_amount' => 0,
            'balance' => $total,
            'currency' => $lease->currency ?? 'EGP',
        ]);

        foreach ($items as $item) {
            InvoiceItem::create($item + ['invoice_id' => $invoice->id]);
        }

        // The marketing levy is now a real line item (charged to the tenant) and
        // funds the property's marketing budget via InvoiceItem's saved hook
        // (MarketingBudget::recomputeAccrued) — derived from source, not incremented.

        return $invoice;
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
