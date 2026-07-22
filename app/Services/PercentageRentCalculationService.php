<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PercentageRentCalculationService
{
    /**
     * Calculate the percentage rent owed for a declaration, based on the lease's
     * percentage-rent configuration. Returns 0 when the lease has no percentage-rent terms.
     *
     * - artificial: percentage_rent = max(0, (sales - threshold) * rate%)
     * - natural_breakpoint: percentage_rent = max(0, sales * rate% - base_rent_monthly)
     */
    public function calculate(TenantSalesDeclaration $declaration): float
    {
        $lease = $declaration->lease;

        if (! $lease instanceof Lease || ! $lease->has_percentage_rent) {
            return 0.0;
        }

        // ANNUAL: each month carries its CANONICAL chronological marginal contribution to the year's
        // cumulative overage — overage(cumulative sales through this month) − overage(through the
        // previous month), each floored at 0. Deterministic (independent of lock order), always ≥ 0
        // (a seasonal spike is netted against slow months, never over-billed against a monthly
        // breakpoint), and the months' marginals sum to overage(total year sales). WHICH month carries
        // the overage is fixed by calendar order, so every lock/void re-trues the WHOLE year
        // (retrueAnnualYear) — removing or adding a month re-attributes the others, keeping the live
        // invoices summing to overage(cumulative). This value is the display estimate; the authoritative
        // per-month figure is written by retrueAnnualYear at lock time.
        if (($lease->percentage_rent_frequency ?? 'monthly') === 'annual') {
            $prior = $this->priorLockedSalesYtd($declaration);
            $withThis = $prior + (float) $declaration->declared_sales;

            return round(max(0.0, $this->overage($lease, $withThis)) - max(0.0, $this->overage($lease, $prior)), 2);
        }

        // MONTHLY (default): the breakpoint applies fresh to this month's sales.
        return round(max(0.0, $this->overage($lease, (float) $declaration->declared_sales)), 2);
    }

    /**
     * The raw (pre-floor) percentage-rent overage on a given sales figure, per the lease's formula.
     * Artificial: (sales − breakpoint) × rate. Natural: sales × rate − base rent (× 12 for an annual
     * lease, whose breakpoint is the ANNUAL base rent).
     */
    private function overage(Lease $lease, float $sales): float
    {
        $rate = (float) $lease->percentage_rent_rate / 100.0;
        $annual = ($lease->percentage_rent_frequency ?? 'monthly') === 'annual';

        if (($lease->percentage_rent_calculation_type ?? 'artificial') === 'natural_breakpoint') {
            return ($sales * $rate) - ((float) $lease->base_rent_monthly * ($annual ? 12 : 1));
        }

        return ($sales - (float) ($lease->percentage_rent_threshold ?? 0)) * $rate;
    }

    /**
     * Cumulative declared sales over the lease's LOCKED periods strictly BEFORE this declaration's
     * period in the same calendar year (chronological running total the annual overage builds on).
     * This declaration's own sales are added by the caller.
     */
    private function priorLockedSalesYtd(TenantSalesDeclaration $declaration): float
    {
        return (float) TenantSalesDeclaration::query()
            ->where('lease_id', $declaration->lease_id)
            ->where('status', 'locked')
            ->where('id', '!=', $declaration->id)
            ->whereYear('period_start', \Illuminate\Support\Carbon::parse($declaration->period_start)->year)
            ->whereDate('period_start', '<', $declaration->period_start)
            ->sum('declared_sales');
    }

    /**
     * The live (not cancelled/credited) percentage-rent overage currently invoiced for THIS period —
     * used by retrueAnnualYear to decide whether a month's invoice already matches its recomputed
     * marginal (skip, no churn) or must be reversed + rebilled.
     */
    private function billedOverageForPeriod(TenantSalesDeclaration $declaration): float
    {
        return (float) InvoiceItem::query()
            ->where('type', 'percentage_rent')
            ->whereHas('charge', fn ($q) => $q->where('lease_id', $declaration->lease_id)
                ->whereDate('start_date', $declaration->period_start))
            ->whereHas('invoice', fn ($q) => $q->whereNotIn('status', ['cancelled', 'credited']))
            ->sum('total');
    }

    /**
     * Re-true an annual lease's whole calendar year so the live overage invoices ALWAYS sum to
     * overage(cumulative year sales). Walks the locked months chronologically and reconciles each to
     * its canonical marginal (overage-through-this − overage-through-previous). Necessary because in
     * the cumulative model WHICH month carries the overage is an artifact of lock order: locking,
     * voiding, or re-locking one month shifts the cumulative the OTHER months were sized against, so
     * touching only the changed month would leave the year over- or under-billed (the exact stale-Feb
     * over-bill a plain void produced before this existed). Runs inside the per-lease mutex +
     * transaction. Reversing a month whose marginal DROPPED throws if that invoice was already PAID
     * (VoidInvoiceService) → the whole operation rolls back and is refused until it is refunded — the
     * same guard the single-period path already has.
     */
    private function retrueAnnualYear(int $leaseId, int $year): void
    {
        $lease = Lease::find($leaseId);
        if (! $lease instanceof Lease) {
            return;
        }

        $locked = TenantSalesDeclaration::query()
            ->where('lease_id', $leaseId)
            ->where('status', 'locked')
            ->whereYear('period_start', $year)
            ->orderBy('period_start')
            ->get();

        $runningPrior = 0.0;
        foreach ($locked as $decl) {
            $withThis = $runningPrior + (float) $decl->declared_sales;
            $marginal = round(max(0.0, $this->overage($lease, $withThis)) - max(0.0, $this->overage($lease, $runningPrior)), 2);
            $runningPrior = $withThis;

            if ((float) $decl->calculated_percentage_rent !== $marginal) {
                $decl->update(['calculated_percentage_rent' => $marginal]);
            }

            if (abs($this->billedOverageForPeriod($decl) - $marginal) < 0.005) {
                continue; // already invoiced correctly — leave it (and any payment) untouched
            }

            $this->reverseOverage($decl); // throws on a PAID invoice → the whole retrue rolls back
            if ($marginal > 0) {
                $this->billOverageImmediately($decl, $marginal);
            }
        }
    }

    /**
     * Recalculate and persist `calculated_percentage_rent` on the declaration without locking.
     */
    public function recalculate(TenantSalesDeclaration $declaration): TenantSalesDeclaration
    {
        $declaration->calculated_percentage_rent = $this->calculate($declaration);
        $declaration->save();

        return $declaration;
    }

    /**
     * Lock a declaration: recalculate, persist, mark as locked, and create a one-off Charge
     * so the next monthly billing run picks the percentage rent up.
     *
     * Idempotent: locking an already-locked declaration is a no-op.
     */
    public function lock(TenantSalesDeclaration $declaration, User $lockedBy, ?string $auditNotes = null): TenantSalesDeclaration
    {
        if ($declaration->status === 'locked') {
            return $declaration;
        }

        // $txn returns the declaration and whether THIS call actually locked it (vs a racing no-op). The
        // tenant notification is sent AFTER the DB transaction commits (still inside the per-lease
        // mutex, but out of the transaction) so a slow/failing notification channel can't extend the
        // transaction — which holds row locks and must stay well under the lease lock's TTL.
        $txn = function () use ($declaration, $lockedBy, $auditNotes) {
            [$declaration, $locked] = DB::transaction(function () use ($declaration, $lockedBy, $auditNotes) {
                // Lock the row + re-check status INSIDE the txn so two concurrent locks (a
                // double-clicked / retried Filament action, or two staff) can't BOTH bill the overage.
                // Under MySQL REPEATABLE READ a non-locking read sees a stale pre-commit snapshot, so
                // both would reach billing → two issued invoices + two GL postings for one declaration.
                // Mirrors VoidInvoiceService / CamReconciliationService (the lock-safe pattern).
                $fresh = TenantSalesDeclaration::query()->lockForUpdate()->find($declaration->id);
                if (! $fresh || $fresh->status === 'locked') {
                    return [$fresh ?? $declaration, false]; // a racing request already locked it — no-op
                }

                $lease = $fresh->lease;
                $isAnnual = $lease instanceof Lease && $lease->percentage_rent_frequency === 'annual';

                $fresh->update([
                    // Annual: retrueAnnualYear writes each month's authoritative figure below.
                    // Monthly: this month's own overage.
                    'calculated_percentage_rent' => $isAnnual ? $fresh->calculated_percentage_rent : $this->calculate($fresh),
                    'status' => 'locked',
                    'locked_at' => now(),
                    'locked_by_user_id' => $lockedBy->id,
                    'audit_notes' => $auditNotes,
                ]);

                if ($isAnnual) {
                    // Re-true the WHOLE calendar year (this newly-locked month + every other locked
                    // month) so the live overage invoices always sum to overage(cumulative year sales),
                    // whatever order months were locked in.
                    $this->retrueAnnualYear($fresh->lease_id, \Illuminate\Support\Carbon::parse($fresh->period_start)->year);
                } else {
                    // Re-lock safety: reverse any prior overage for this lease+period before billing the
                    // fresh one, so a re-lock can never double-bill.
                    $this->reverseOverage($fresh);
                    $owed = (float) $fresh->calculated_percentage_rent;
                    if ($owed > 0) {
                        $this->billOverageImmediately($fresh, $owed);
                    }
                }

                return [$fresh->refresh(), true];
            });

            // Even a zero charge is useful to the tenant (they were under-threshold). Out of the txn.
            if ($locked) {
                $this->notifyLocked($declaration);
            }

            return $declaration;
        };

        // Annual billing reads/adjusts the lease's OTHER declarations + invoices, so two concurrent
        // locks of DIFFERENT months of the SAME lease must not interleave (each would miss the other's
        // uncommitted charge under REPEATABLE READ and mis-bill). Serialize every lock/void for one
        // annual lease behind a per-lease mutex (Cache::lock, cf. the single-lease billing lock);
        // monthly leases are independent per month → no lock.
        return $this->runSerializedPerLease($declaration, $txn);
    }

    private function notifyLocked(TenantSalesDeclaration $declaration): void
    {
        $tenant = $declaration->lease?->tenant;
        if (! $tenant) {
            return;
        }

        try {
            $tenant->notifyPortal(new \App\Notifications\SalesDeclarationLockedNotification($declaration->refresh()));
        } catch (\Throwable $e) {
            \Log::warning('Sales declaration locked notification failed', [
                'declaration_id' => $declaration->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Void a previously-locked declaration: flip status to `disputed`, reverse the overage
     * (deactivate the anchor Charge AND cancel its immediate invoice, so the GL entry is
     * voided by the sweep), and stamp audit_notes with the reason. A PAID overage invoice
     * can't be voided — VoidInvoiceService throws, the txn rolls back, the void is refused.
     *
     * Idempotent: voiding a non-locked declaration is a no-op (the action
     * is UI-gated to status=locked, but we belt-and-braces in case a future
     * caller doesn't gate). Audit M12 F-48 / D-36.
     */
    public function voidLocked(TenantSalesDeclaration $declaration, User $voidedBy, string $reason): TenantSalesDeclaration
    {
        if ($declaration->status !== 'locked') {
            return $declaration;
        }

        $txn = fn () => DB::transaction(function () use ($declaration, $voidedBy, $reason) {
            // Lock the row + re-check inside the txn (same rationale as lock()): two racing
            // voids must not both run reverseOverage() and double-cancel / double-refund.
            $declaration = TenantSalesDeclaration::query()->lockForUpdate()->find($declaration->id);
            if (! $declaration || $declaration->status !== 'locked') {
                return $declaration; // already voided by a racing request — nothing to do
            }

            // Reverse the overage: deactivate the anchor Charge AND void its immediate
            // invoice (the overage was already billed at lock time). If that invoice has
            // been PAID, VoidInvoiceService throws — the void is refused until it's refunded.
            $this->reverseOverage($declaration);

            $existing = $declaration->audit_notes ? rtrim($declaration->audit_notes) . "\n\n" : '';
            $stamp = now()->format('Y-m-d');
            $note = "Voided on {$stamp} by {$voidedBy->name}: {$reason}";

            $declaration->update([
                'status' => 'disputed',
                'audit_notes' => $existing . $note,
            ]);

            // Annual: this month's sales just left the cumulative, so the OTHER locked months — sized
            // against a running total that included it — must be re-trued, or the year stays over-billed
            // (the exact stale-invoice bug a per-period void produced). Re-truing may reverse another
            // month's now-too-large invoice; if that invoice is PAID it throws and the void is refused.
            $lease = $declaration->lease;
            if ($lease instanceof Lease && $lease->percentage_rent_frequency === 'annual') {
                $this->retrueAnnualYear(
                    $declaration->lease_id,
                    \Illuminate\Support\Carbon::parse($declaration->period_start)->year
                );
            }

            return $declaration->refresh();
        });

        // Serialize with lock() on the same annual lease (see runSerializedPerLease): a void shifts
        // the cumulative that a racing lock reads, so both must run one at a time per lease.
        return $this->runSerializedPerLease($declaration, $txn);
    }

    /**
     * Run $txn either directly (monthly lease — each month is independent) or, for an ANNUAL lease,
     * inside a per-lease mutex so every lock/void for that lease serializes. The annual cumulative
     * delta is derived from the lease's other declarations + already-billed invoices, which two
     * concurrent operations would otherwise read stale (REPEATABLE READ) and under-bill. Blocks up to
     * 10s for the lock; the lock self-expires after 10s so a crashed holder can't wedge the lease.
     *
     * @param  callable():TenantSalesDeclaration  $txn
     */
    private function runSerializedPerLease(TenantSalesDeclaration $declaration, callable $txn): TenantSalesDeclaration
    {
        $lease = $declaration->lease;
        if (! $lease instanceof Lease || $lease->percentage_rent_frequency !== 'annual') {
            return $txn(); // monthly lease (or a null default) — each month is independent, no lock
        }

        return Cache::lock('pct-rent:lock:lease:'.$declaration->lease_id, 10)->block(10, $txn);
    }

    /**
     * Bill the percentage-rent overage IMMEDIATELY as its own issued invoice. The monthly
     * billing run can't reach a one_time charge dated to a past sales month, so — mirroring the
     * CAM positive true-up (billChargeImmediately) — we invoice it now. The Charge is kept as an
     * INACTIVE traceability anchor + the void/re-lock identity key (matched on
     * start_date = period_start); the money lives on the invoice, posting to the GL as
     * percentage_rent_revenue via the invoice item's `percentage_rent` type.
     */
    private function billOverageImmediately(TenantSalesDeclaration $declaration, float $amount): Charge
    {
        /** @var Lease $lease */
        $lease = $declaration->lease;
        $now = now();
        $label = 'Percentage Rent — '.$declaration->periodLabel();

        // Anchor: is_active=false so the monthly engine (which loads only active charges)
        // never re-bills it; dated to the sales period so void/re-lock match it.
        $charge = Charge::create([
            'lease_id' => $lease->id,
            'name' => $label,
            'type' => 'percentage_rent',
            'amount' => $amount,
            'currency' => 'EGP',
            'frequency' => 'one_time',
            'vat_applicable' => false,
            'vat_rate' => 0,
            'start_date' => $declaration->period_start,
            'end_date' => $declaration->period_end,
            'is_active' => false,
        ]);

        // Invoice period = the SALES period (the truthful period the overage covers), NOT
        // now(). That single-month span DOES fall inside MonthlyBillingService's "already
        // billed" window, so both of its idempotency probes explicitly exclude pure
        // percentage-rent overage invoices (whereDoesntHave items type=percentage_rent) —
        // otherwise a back-filled monthly run for this month would skip the base rent.
        $invoice = Invoice::create([
            'lease_id' => $lease->id,
            'tenant_id' => $lease->tenant_id,
            'status' => 'issued',
            'issue_date' => $now,
            'due_date' => $now->copy()->addDays($lease->payment_terms_days ?? 7),
            'period_start' => $declaration->period_start,
            'period_end' => $declaration->period_end,
            'subtotal' => $amount,
            'vat_amount' => 0,
            'total' => $amount,
            'paid_amount' => 0,
            'balance' => $amount,
            'currency' => $lease->currency ?? 'EGP',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'charge_id' => $charge->id,
            'description' => $label,
            'type' => 'percentage_rent', // → percentage_rent_revenue in the GL journalizer
            'amount' => $amount,
            'vat_rate' => 0,
            'vat_amount' => 0,
            'total' => $amount,
        ]);

        return $charge;
    }

    /**
     * Reverse a period's overage (used by void AND re-lock): deactivate the anchor Charge(s)
     * and void their immediate invoice. Matched by (lease, type, start_date = period_start) so
     * a sibling period is never touched. A PAID overage invoice can't be voided —
     * VoidInvoiceService throws, the caller's transaction rolls back, and the void/re-lock is
     * refused (the invoice must be refunded first).
     */
    private function reverseOverage(TenantSalesDeclaration $declaration): void
    {
        $charges = Charge::where('lease_id', $declaration->lease_id)
            ->where('type', 'percentage_rent')
            ->whereDate('start_date', $declaration->period_start)
            ->get();

        foreach ($charges as $charge) {
            $charge->update(['is_active' => false, 'end_date' => now()]);

            $invoice = InvoiceItem::where('charge_id', $charge->id)->latest('id')->first()?->invoice;
            if ($invoice && ! in_array($invoice->status, ['cancelled', 'credited'], true)) {
                app(VoidInvoiceService::class)->void($invoice, 'Percentage-rent declaration voided / re-locked');
            }
        }
    }
}
