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
                    // would miss it and bill the month a SECOND time (full).
                    $alreadyBilled = Invoice::where('lease_id', $lease->id)
                        ->whereDate('period_start', '>=', $periodStart->toDateString())
                        ->whereDate('period_start', '<=', $periodEnd->toDateString())
                        ->exists();

                    if ($alreadyBilled) {
                        $stats['skipped']++;
                        continue;
                    }

                    try {
                        $invoice = DB::transaction(function () use ($lease, $periodStart, $periodEnd) {
                            return $this->generateInvoiceForLease($lease, $periodStart, $periodEnd);
                        });
                        $stats['created']++;
                        if ($invoice) {
                            $this->notifyInvoiceIssued($invoice);
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
        $periodStart = $period;
        $periodEnd = $period->endOfMonth();

        // Period-OVERLAP guard (see runForPeriod): catches a prorated first-month
        // invoice whose period_start is the mid-month commencement, so re-running
        // for that month — prorate on or off — can't double-bill.
        $alreadyBilled = Invoice::where('lease_id', $lease->id)
            ->whereDate('period_start', '>=', $periodStart->toDateString())
            ->whereDate('period_start', '<=', $periodEnd->toDateString())
            ->exists();

        if ($alreadyBilled) {
            return ['status' => 'skipped', 'reason' => 'already_billed', 'invoice' => null];
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

        $issueDate = $effectivePeriodStart;
        $dueDate = $issueDate->addDays($lease->payment_terms_days ?? 7);

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
