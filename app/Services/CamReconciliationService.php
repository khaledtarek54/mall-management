<?php

namespace App\Services;

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Support\OpsLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CamReconciliationService
{
    /**
     * Generate one CamAllocation per active lease in the pool's asset,
     * with each lease's pro-rata share computed from leased sqm.
     *
     * Allocated amount = (lease unit sqm / total leased sqm) * total_actual_expense.
     * Estimated paid = (lease unit sqm / total leased sqm) * total_estimated_collected.
     * True-up = allocated - estimated. Positive means under-collected (tenant owes more).
     *
     * Idempotent: existing allocations are updated, not duplicated.
     */
    public function generateAllocations(CamExpensePool $pool): int
    {
        $leases = Lease::query()
            ->whereHas('unit', fn ($q) => $q->where('asset_id', $pool->asset_id))
            ->where('status', 'active')
            ->with('unit')
            ->get();

        $totalSqm = (float) $leases->sum(fn (Lease $l) => (float) ($l->unit?->area_sqm ?? 0));

        if ($totalSqm <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($pool, $leases, $totalSqm) {
            $count = 0;

            foreach ($leases as $lease) {
                $sqm = (float) ($lease->unit?->area_sqm ?? 0);
                if ($sqm <= 0) {
                    continue;
                }

                $share = $sqm / $totalSqm;
                $allocated = round((float) $pool->total_actual_expense * $share, 2);
                $estimated = round((float) $pool->total_estimated_collected * $share, 2);
                $trueUp = round($allocated - $estimated, 2);

                // Lock the existing row inside the txn so the status check below
                // sees committed truth — a concurrent bill() that flipped it to
                // 'billed' between a stale read and our save would otherwise be
                // clobbered back to 'pending' and re-billed (double charge/credit).
                $allocation = CamAllocation::query()
                    ->where('cam_expense_pool_id', $pool->id)
                    ->where('lease_id', $lease->id)
                    ->lockForUpdate()
                    ->first();

                // Never re-touch an allocation that's already been billed.
                if ($allocation && $allocation->status !== 'pending') {
                    continue;
                }

                $allocation ??= new CamAllocation([
                    'cam_expense_pool_id' => $pool->id,
                    'lease_id' => $lease->id,
                ]);

                $allocation->fill([
                    'pro_rata_share_pct' => round($share * 100, 4),
                    'allocated_amount' => $allocated,
                    'estimated_paid' => $estimated,
                    'true_up_amount' => $trueUp,
                    'status' => 'pending',
                ]);
                $allocation->save();
                $count++;
            }

            return $count;
        });
    }

    /**
     * Full annual reconciliation lifecycle for every pool in a given year.
     *
     * For each draft/reconciling pool: generate allocations, optionally bill
     * each one, then bump pool status. Idempotent at every step:
     * already-billed allocations are skipped, already-reconciled pools are
     * skipped. Returns a stats array per pool so the caller (CLI / scheduled
     * job / admin action) can report exactly what happened.
     *
     * @return array<int, array{pool_id:int, asset:string, allocations:int, billed:int, status:string}>
     */
    public function autoTrueUpForYear(int $year, bool $autoBill = false): array
    {
        $pools = CamExpensePool::query()
            ->where('period_year', $year)
            ->whereIn('status', ['draft', 'reconciling'])
            ->with('asset')
            ->get();

        $report = [];
        $failed = 0;

        foreach ($pools as $pool) {
            try {
                $allocations = $this->generateAllocations($pool);
                $billed = 0;

                if ($autoBill) {
                    foreach ($pool->allocations()->where('status', 'pending')->get() as $allocation) {
                        $this->bill($allocation);
                        $billed++;
                    }
                }

                // Pool moves to 'reconciled' once allocations exist and billing was
                // requested; otherwise to 'reconciling' so the admin queue surfaces
                // it for manual review.
                $nextStatus = match (true) {
                    $autoBill && $allocations > 0 => 'reconciled',
                    $allocations > 0 => 'reconciling',
                    default => $pool->status,
                };

                if ($nextStatus !== $pool->status) {
                    $pool->update([
                        'status' => $nextStatus,
                        'reconciled_at' => $nextStatus === 'reconciled' ? now() : $pool->reconciled_at,
                    ]);
                }

                $report[] = [
                    'pool_id' => $pool->id,
                    'asset' => $pool->asset?->name ?? '—',
                    'allocations' => $allocations,
                    'billed' => $billed,
                    'status' => $pool->fresh()->status,
                ];
            } catch (\Throwable $e) {
                // One bad pool shouldn't abort the whole annual run — log + continue.
                $failed++;
                OpsLog::error('cam.pool_failed', [
                    'pool_id' => $pool->id,
                    'year' => $year,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        OpsLog::info('cam.run_complete', [
            'year' => $year,
            'pools' => $pools->count(),
            'reconciled' => count($report),
            'failed' => $failed,
            'auto_bill' => $autoBill,
        ]);

        return $report;
    }

    /**
     * Bill a single allocation for its true-up.
     *  - Positive true-up (tenant owes more) → a one-off Charge that lands on the
     *    next invoice.
     *  - Negative true-up (tenant over-paid) → a CreditNote on the tenant's
     *    account. (Previously this was a negative one-off Charge; if the credit
     *    exceeded January's other charges the invoice total went negative and
     *    Invoice::recomputeTotals() floored it to 0 — silently LOSING the credit.
     *    A CreditNote preserves it and settles future AR.)
     *
     * Idempotent + lock-safe: re-billing an already-billed allocation is a no-op.
     */
    public function bill(CamAllocation $allocation): CamAllocation
    {
        return DB::transaction(function () use ($allocation) {
            // Re-load under a row lock and re-check INSIDE the txn — two concurrent
            // bill() calls would otherwise both pass a stale status check and each
            // bill the true-up (double-bill).
            $allocation = CamAllocation::query()->lockForUpdate()->find($allocation->id);

            if (! $allocation || $allocation->status === 'billed') {
                return $allocation;
            }

            $pool = $allocation->pool;
            $year = $pool->period_year;
            $amount = (float) $allocation->true_up_amount;

            if ($amount < 0) {
                $note = $this->billCredit($allocation, abs($amount), $year);

                // Auto-apply to the lease's open invoices (FIFO), restoring the
                // old negative-charge behaviour where the credit netted against
                // what the tenant owes — instead of sitting unapplied until an
                // admin remembers to click Apply. Any remainder stays on the
                // note as a standing credit (preserved, never lost).
                $this->applyCreditToOpenInvoices($note, $allocation->lease_id);

                $allocation->update([
                    'status' => 'billed',
                    'billed_credit_note_id' => $note->id,
                ]);

                return $allocation->refresh();
            }

            $charge = $this->billChargeImmediately($allocation, $amount, $year);

            $allocation->update([
                'status' => 'billed',
                'billed_charge_id' => $charge->id,
            ]);

            return $allocation->refresh();
        });
    }

    /**
     * Settle a POSITIVE true-up (tenant owes more) on a dedicated recovery
     * invoice IMMEDIATELY — never via a future-dated one_time charge that the
     * monthly engine may skip. Reconciliation runs the year after the reconciled
     * year is fully billed, AND the most likely under-collected tenant has an
     * ended-term lease (excluded from the monthly run by the active/expiry
     * filter), so deferring to a monthly run = silent lost revenue regardless of
     * the date. Mirrors the negative path, which applies the credit immediately.
     */
    private function billChargeImmediately(CamAllocation $allocation, float $amount, int $year): Charge
    {
        /** @var Lease $lease */
        $lease = $allocation->lease;
        $now = CarbonImmutable::now();
        $name = "CAM Reconciliation — {$year}";

        // The Charge is kept for traceability + the books CAM check.
        $charge = Charge::create([
            'lease_id' => $lease->id,
            'name' => $name,
            'type' => 'other',
            'amount' => $amount,
            'currency' => 'EGP',
            'frequency' => 'one_time',
            'vat_applicable' => false,
            'vat_rate' => 0,
            'start_date' => $now->startOfMonth(),
            'end_date' => $now->endOfMonth(),
            'is_active' => true,
        ]);

        $invoice = Invoice::create([
            'lease_id' => $lease->id,
            'tenant_id' => $lease->tenant_id,
            'status' => 'issued',
            'issue_date' => $now,
            'due_date' => $now->addDays($lease->payment_terms_days ?? 7),
            // Period = the RECONCILED CAM YEAR, NOT the current month. The monthly
            // billing engine's idempotency is a per-lease period-OVERLAP check; if
            // this recovery invoice carried the current month's period it would
            // satisfy that guard and make the monthly run SKIP the lease's regular
            // rent invoice for the month (lost revenue). A past-year period never
            // collides with a live monthly run.
            'period_start' => CarbonImmutable::create($year, 1, 1),
            'period_end' => CarbonImmutable::create($year, 12, 31),
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
            'description' => $name,
            'type' => 'other',
            'amount' => $amount,
            'vat_rate' => 0,
            'vat_amount' => 0,
            'total' => $amount,
        ]);

        return $charge;
    }

    /** Apply a freshly-issued credit to the lease's open invoices, oldest first. */
    private function applyCreditToOpenInvoices(CreditNote $note, int $leaseId): void
    {
        $service = app(CreditNoteService::class);

        $openInvoices = Invoice::query()
            ->where('lease_id', $leaseId)
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->where('balance', '>', 0)
            ->orderBy('due_date')
            ->get();

        foreach ($openInvoices as $invoice) {
            if ((float) (CreditNote::whereKey($note->id)->value('balance') ?? 0) <= 0) {
                break;
            }
            $service->applyToInvoice($note, $invoice);
        }
    }

    /** A negative true-up becomes an issued credit on the tenant's account. */
    private function billCredit(CamAllocation $allocation, float $credit, int $year): CreditNote
    {
        /** @var Lease $lease */
        $lease = $allocation->lease;

        return CreditNote::create([
            'tenant_id' => $lease->tenant_id,
            'lease_id' => $lease->id,
            'status' => 'issued',
            'issue_date' => now(),
            'reason' => 'adjustment',
            'reason_notes' => "CAM reconciliation credit — {$year}",
            'subtotal' => $credit,
            'vat_amount' => 0,
            'total' => $credit,
            'applied_amount' => 0,
            'balance' => $credit,
            'currency' => 'EGP',
        ]);
    }
}
