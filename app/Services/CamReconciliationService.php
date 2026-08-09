<?php

namespace App\Services;

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Support\Vat;
use App\Support\OpsLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CamReconciliationService
{
    /**
     * Generate one CamAllocation per active lease in the pool's asset,
     * with each lease's pro-rata share computed from leased sqm.
     *
     * Area is the lease's TOTAL over every unit on it (Lease::totalAreaSqm()), not the master
     * unit's — see that method for why reading the master alone mis-distributes the pool while
     * leaving the tie-out green.
     *
     * Allocated amount = (lease total sqm / total leased sqm) * total_actual_expense.
     * Estimated paid = (lease total sqm / total leased sqm) * total_estimated_collected.
     * True-up = allocated - estimated. Positive means under-collected (tenant owes more).
     *
     * Idempotent: existing allocations are updated, not duplicated.
     */
    public function generateAllocations(CamExpensePool $pool): int
    {
        // On a RE-RUN, keep the ORIGINAL participant set AND the share basis frozen. The set is
        // pinned via $existingLeaseIds; the SHARES are pinned by REUSING each existing allocation's
        // stored pro_rata_share_pct instead of recomputing from live sqm. Otherwise a unit-area
        // edit — or a soft-deleted participant — between runs would shift the sqm denominator, and
        // with some allocations already billed (frozen) the remaining pending ones would recompute
        // against the new denominator → Σ(allocated) ≠ total_actual_expense (broken tie-out) and
        // over-/under-billed tenants. The pool freeze guard protects the expense basis; this
        // protects the area basis, its missing counterpart.
        $existingLeaseIds = $pool->allocations()->pluck('lease_id')->all();
        $isRerun = ! empty($existingLeaseIds);

        $leases = $isRerun
            ? Lease::query()->whereIn('id', $existingLeaseIds)->with('unit', 'units')->get()
            : Lease::query()
                ->whereHas('unit', fn ($q) => $q->where('asset_id', $pool->asset_id))
                ->where('status', 'active')
                ->with('unit', 'units')
                ->get();

        // Frozen shares (as a fraction) for the re-run path; the sqm denominator for the first run.
        $frozenShares = $isRerun
            ? $pool->allocations()->pluck('pro_rata_share_pct', 'lease_id')
            : collect();

        // The pool covers a YEAR, so the area basis is the TIME-WEIGHTED area over that year
        // (LE-02): a tenant who took an extra 300 m² on 1 November has not occupied it for the
        // year and must not carry a whole year of its CAM. For every lease whose premises are
        // undated — all of them until an expansion or contraction is recorded — this is identical
        // to the flat total, so no existing pool changes basis.
        $periodStart = CarbonImmutable::create((int) $pool->period_year, 1, 1)->startOfDay();
        $periodEnd = $periodStart->endOfYear()->startOfDay();

        // The DENOMINATOR the shares divide by (story RC-03). `occupied` — the legacy basis and
        // the default — is the summed area of the participants, which recovers 100% of the pool
        // from whoever happens to be trading. That is what some leases say; others say share of
        // GROSS leasable area, which leaves the vacancy with the landlord.
        $totalSqm = $isRerun ? 0.0 : $this->resolveDenominator($pool, $leases, $periodStart, $periodEnd);

        if (! $isRerun && $totalSqm <= 0) {
            return 0;
        }

        // The expense the shares apportion over (story RC-04). Identical to `total_actual_expense`
        // unless the pool grosses up, which is every pool that exists today. Occupancy is measured
        // as the participants' area over the denominator actually used — the same two numbers the
        // shares divide, so the gross-up can never disagree with the apportionment it feeds.
        // On a RE-RUN the denominator is frozen with the shares, so occupancy is measured against
        // the stored one; a pool reconciled before RC-03 has none, which reads as 0 and grosses
        // nothing. Either way the basis is recomputed from the CURRENT expense.
        //
        // Re-using the stored `grossed_up_expense` here would have been the obvious shortcut and it
        // was wrong: the frozen-share guard freezes the AREA basis so a unit-area edit cannot
        // re-cut anybody's share, but a REVISED POOL EXPENSE is exactly what a re-run exists to
        // push through. Caught by `CamScenarioTest`, which pins that revision.
        $referenceSqm = $isRerun ? (float) $pool->denominator_used_sqm : $totalSqm;

        $occupancyPct = $referenceSqm > 0
            ? $this->occupiedDenominator($leases, $periodStart, $periodEnd) / $referenceSqm * 100
            : 0.0;

        $basis = $pool->apportionmentBasis($occupancyPct);

        return DB::transaction(function () use ($pool, $leases, $totalSqm, $isRerun, $frozenShares, $periodStart, $periodEnd, $basis) {
            $count = 0;
            $allocatedTotal = 0.0;

            foreach ($leases as $lease) {
                if ($isRerun) {
                    // Reuse the established share — never recompute from live sqm on a re-run.
                    $share = (float) ($frozenShares[$lease->id] ?? 0) / 100;
                    if ($share <= 0) {
                        continue;
                    }
                } else {
                    // A lease whose contract NAMES its percentage wins over any derived one — no
                    // denominator can produce a number the parties simply agreed (RC-03). Common in
                    // Egyptian leases, and previously unrepresentable.
                    $stated = $lease->statedCamSharePct((int) $pool->period_year);

                    if ($stated !== null) {
                        $share = $stated / 100;
                    } else {
                        $sqm = $lease->totalAreaSqmForPeriod($periodStart, $periodEnd);
                        if ($sqm <= 0) {
                            continue;
                        }
                        $share = $sqm / $totalSqm;
                    }
                }

                $allocated = round($basis * $share, 2);
                // What this tenant actually paid in estimates (story RC-05). On `billed` the
                // figure comes from the invoices themselves, so the estimate RECONCILED and the
                // estimate BILLED are the same number by construction. On `stated` — every pool
                // that already exists — it stays the pro-rata slice of a hand-typed total, which
                // is what those years were reconciled against and must keep being.
                $estimated = $pool->estimate_basis === CamExpensePool::BASIS_BILLED
                    ? app(SyncCamPoolFromLedgerService::class)->estimateBilledFor($pool, $lease)
                    : round((float) $pool->total_estimated_collected * $share, 2);

                // Cap: a ceiling the tenant's CAM cost share can't exceed this year. It hits ONLY
                // the true-up + the admin-fee base — allocated_amount stays uncapped, so the
                // books-check's Σ allocated = total_actual_expense tie-out is untouched. The
                // landlord ABSORBS the difference (cap_absorbed). No cap term ⇒ ceiling null ⇒
                // capped_cost = allocated ⇒ true-up + fee byte-identical to the no-cap slice.
                $ceiling = $lease->resolveCamCeiling((int) $pool->period_year);
                $cappedCost = $ceiling !== null ? min($allocated, $ceiling) : $allocated;
                $capAbsorbed = round($allocated - $cappedCost, 2);

                $trueUp = round($cappedCost - $estimated, 2);

                // Admin fee = the routine % the landlord adds on top of the recovered pool
                // (operator default 10%, 14% VAT). It is a SIBLING of the true-up — its own
                // invoice line + charge, never folded into true_up_amount — so allocated_amount
                // stays the pass-through cost the books-check ties out against. Base = the CAPPED
                // (net) cost the tenant actually bears, so the fee can't re-breach the cap. A null
                // admin_fee_pct (existing pools, no clause) yields 0 → byte-identical billing.
                $adminFeePct = (float) ($pool->admin_fee_pct ?? 0);
                $adminFee = round($adminFeePct * $cappedCost, 2);
                $adminFeeVat = Vat::on($adminFee);

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
                    'cap_amount' => $ceiling,           // the resolved ceiling that applied (null = uncapped)
                    'capped_cost_amount' => $cappedCost,
                    'cap_absorbed_amount' => $capAbsorbed,
                    'true_up_amount' => $trueUp,
                    'admin_fee_amount' => $adminFee,
                    'admin_fee_vat_amount' => $adminFeeVat,
                    // Next year's proposed monthly estimate (story RC-05). Proposing is not
                    // applying: it is written here so the operator can review the whole mall's
                    // proposals side by side, and `ApplyCamEstimateService` is what puts an
                    // accepted one on the lease's charge schedule.
                    //
                    // Based on the CAPPED cost — what the tenant actually bears — not the uncapped
                    // allocation, or a capped tenant would be asked to pre-pay an amount their own
                    // clause forbids the landlord to charge.
                    'proposed_monthly_estimate' => round($cappedCost / 12, 2),
                    'status' => 'pending',
                ]);
                $allocation->save();
                $allocatedTotal += $allocated;
                $count++;
            }

            // What the landlord bears itself — the part of the pool no lease's share reached.
            //
            // Under `occupied` this is 0.00 (the shares sum to 100% by construction) and nothing
            // about the books changes. Under `gla` it is the vacancy: real money, previously
            // invisible, and previously read by the books tie-out as DRIFT. Storing it is what lets
            // `Σ allocated + unrecovered = total` stay a hard check instead of being loosened.
            //
            // A NEGATIVE value means the pool over-recovered — stated shares that sum past 100%.
            // That is a data problem worth seeing, so it is recorded rather than clamped to zero.
            // The basis actually applied and what the landlord bore are OUTPUTS of this run, so
            // they are rewritten every time — including a re-run, which exists precisely to push a
            // revised expense through. Leaving them frozen would have left
            // `Σ allocated + unrecovered = total` failing after any revision, silently.
            //
            // `denominator_used_sqm` is the exception: it is the frozen area basis, and re-deriving
            // it would let a unit-area edit re-cut shares the guard exists to protect.
            $written = [
                'grossed_up_expense' => round($basis, 2),
                // Measured against what the landlord ACTUALLY SPENT, never against the grossed
                // basis: gross-up changes how the cost is shared, not how much of it exists.
                'landlord_unrecovered_amount' => round((float) $pool->total_actual_expense - $allocatedTotal, 2),
            ];

            if (! $isRerun) {
                $written['denominator_used_sqm'] = round($totalSqm, 2);
            }

            $pool->forceFill($written)->save();

            return $count;
        });
    }

    /**
     * The area every share divides by (story RC-03).
     *
     * @param  \Illuminate\Support\Collection<int, Lease>  $leases
     */
    private function resolveDenominator(
        CamExpensePool $pool,
        $leases,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): float {
        return match ($pool->denominator_basis) {
            // The property's GROSS leasable area: vacancy stays with the landlord, which is what a
            // lease means when it says "share of the total leasable area of the centre". Falls back
            // to the summed unit areas when no GLA is declared — the two are the same intent, and
            // `Asset::totalUnitAreaSqm()` is the one already used for occupancy.
            CamExpensePool::DENOMINATOR_GLA => (float) ($pool->asset?->leasable_area_sqm > 0
                ? $pool->asset->leasable_area_sqm
                : ($pool->asset?->totalUnitAreaSqm() ?? 0)),

            // Contractually pinned. Zero or null is treated as unusable and falls through to the
            // occupied basis rather than allocating nothing — a mis-typed pool should reconcile
            // like it always did, not silently recover zero from everyone.
            CamExpensePool::DENOMINATOR_FIXED => (float) $pool->denominator_fixed_sqm > 0
                ? (float) $pool->denominator_fixed_sqm
                : $this->occupiedDenominator($leases, $periodStart, $periodEnd),

            default => $this->occupiedDenominator($leases, $periodStart, $periodEnd),
        };
    }

    /** @param  \Illuminate\Support\Collection<int, Lease>  $leases */
    private function occupiedDenominator($leases, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): float
    {
        return (float) $leases->sum(fn (Lease $l) => $l->totalAreaSqmForPeriod($periodStart, $periodEnd));
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
    /**
     * A plain-language breakdown of how one lease's CAM true-up is worked out — so an operator (and a
     * disputing tenant) can SEE every leg, not just the bare allocated/true-up columns. Surfaces the
     * cap legs (ceiling / capped cost / landlord-absorbed) that make the visible columns stop adding up
     * when a cap bites, plus the recovery VAT and admin-fee VAT that never appeared in the table, and
     * the net the tenant is actually invoiced (positive true-up) or credited (over-payment).
     *
     * @return array<string, mixed>
     */
    public function explainAllocation(CamAllocation $allocation): array
    {
        $pool = $allocation->pool instanceof CamExpensePool ? $allocation->pool : null;
        $recoveryVatRate = $pool ? (float) $pool->recovery_vat_rate : 0.0;
        $trueUp = (float) $allocation->true_up_amount;
        $recovery = max(0.0, $trueUp);
        $recoveryVat = round($recovery * $recoveryVatRate / 100.0, 2);
        $fee = (float) $allocation->admin_fee_amount;
        $feeVat = (float) $allocation->admin_fee_vat_amount;

        $direction = $trueUp > 0.005 ? 'recover' : ($trueUp < -0.005 ? 'credit' : 'fee_only');

        return [
            'share_pct' => (float) $allocation->pro_rata_share_pct,
            'pool_actual' => $pool ? (float) $pool->total_actual_expense : 0.0,
            'allocated' => (float) $allocation->allocated_amount,
            // A cap bit only when it actually absorbed cost; the ceiling alone (== allocated) is a no-op.
            'cap_applied' => $allocation->cap_amount !== null && (float) $allocation->cap_absorbed_amount > 0.005,
            'cap_ceiling' => $allocation->cap_amount !== null ? (float) $allocation->cap_amount : null,
            'capped_cost' => (float) $allocation->capped_cost_amount,
            'cap_absorbed' => (float) $allocation->cap_absorbed_amount,
            'estimated_paid' => (float) $allocation->estimated_paid,
            'true_up' => $trueUp,
            'recovery_vat_rate' => $recoveryVatRate,
            'recovery_vat' => $recoveryVat,
            'admin_fee' => $fee,
            'admin_fee_vat' => $feeVat,
            'direction' => $direction,
            // What the tenant is actually invoiced (recover / fee-only) — true-up + its VAT + the fee +
            // fee VAT. For a credit (over-payment), the credit note carries |true-up| + its recovery VAT
            // and the admin fee bills separately.
            'net_invoiced' => $direction === 'credit'
                ? round(abs($trueUp) * (1 + $recoveryVatRate / 100.0), 2)
                : round($recovery + $recoveryVat + $fee + $feeVat, 2),
        ];
    }

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
            $adminFee = (float) $allocation->admin_fee_amount;
            $adminFeeVat = (float) $allocation->admin_fee_vat_amount;
            $hasFee = $adminFee > 0.005;

            $update = ['status' => 'billed'];

            if ($amount < 0) {
                $note = $this->billCredit($allocation, abs($amount), $year);

                // Auto-apply to the lease's open invoices (FIFO), restoring the
                // old negative-charge behaviour where the credit netted against
                // what the tenant owes — instead of sitting unapplied until an
                // admin remembers to click Apply. Any remainder stays on the
                // note as a standing credit (preserved, never lost).
                $this->applyCreditToOpenInvoices($note, $allocation->lease_id);

                $update['billed_credit_note_id'] = $note->id;

                // The admin fee is a POSITIVE amount owed — it can't ride the credit note,
                // so it gets its own fee-only recovery invoice (created AFTER the credit is
                // applied above, so an over-collection credit never silently nets it away).
                if ($hasFee) {
                    $update['billed_admin_fee_charge_id'] =
                        $this->billAdminFee($allocation, $adminFee, $adminFeeVat, $year, null)->id;
                }

                $allocation->update($update);

                return $allocation->refresh();
            }

            if (abs($amount) < 0.005) {
                // Estimate exactly matched actual — nothing to recover or refund.
                // Settle the allocation without emitting a phantom zero-amount
                // charge (which would create a 0.00 recovery invoice / GL noise).
                // The fee is still owed on the recovered pool, so it bills on its own invoice.
                if ($hasFee) {
                    $update['billed_admin_fee_charge_id'] =
                        $this->billAdminFee($allocation, $adminFee, $adminFeeVat, $year, null)->id;
                }

                $allocation->update($update);

                return $allocation->refresh();
            }

            // Positive true-up: settle the cost recovery and (if any) the admin fee on the
            // SAME recovery invoice — one document for the tenant, the fee a second VAT-bearing line.
            [$charge, $invoice] = $this->billChargeImmediately($allocation, $amount, $year);
            $update['billed_charge_id'] = $charge->id;

            if ($hasFee) {
                $update['billed_admin_fee_charge_id'] =
                    $this->billAdminFee($allocation, $adminFee, $adminFeeVat, $year, $invoice)->id;
            }

            $allocation->update($update);

            return $allocation->refresh();
        });
    }

    /**
     * Un-bill an allocation: reverse every financial document it created and reset it to `pending`,
     * so the operator can correct the pool basis (the freeze guard's "void the billed allocations
     * first") and re-bill. Reverses:
     *  - the recovery invoice (positive true-up) — which also carries the fee line, if any;
     *  - the credit note (negative true-up) — un-applied from the invoices it settled, then voided;
     *  - the fee-only invoice (negative / zero true-up).
     * A recovery invoice the tenant has already PAID blocks the void (VoidInvoiceService refuses
     * captured cash) — refund the payment first. Idempotent + lock-safe (a non-billed allocation is
     * a no-op).
     */
    public function voidAllocation(CamAllocation $allocation): CamAllocation
    {
        return DB::transaction(function () use ($allocation) {
            $allocation = CamAllocation::query()->lockForUpdate()->find($allocation->id);
            if (! $allocation || $allocation->status !== 'billed') {
                return $allocation; // only a billed allocation can be un-billed
            }

            // The credit note first: un-apply it from the invoices it settled (re-opening their AR),
            // then void it (void refuses an applied note, so the un-apply must come first).
            if ($allocation->billed_credit_note_id) {
                $note = CreditNote::find($allocation->billed_credit_note_id);
                if ($note) {
                    $creditSvc = app(CreditNoteService::class);
                    $creditSvc->reverseAllApplications($note);
                    $creditSvc->void($note, 'CAM reconciliation voided');
                }
            }

            // Void each DISTINCT invoice backing the allocation. For a positive true-up the recovery
            // charge + the fee charge share ONE invoice (unique() collapses them); for a negative/zero
            // true-up the fee has its own fee-only invoice.
            $invoiceIds = collect([$allocation->billed_charge_id, $allocation->billed_admin_fee_charge_id])
                ->filter()
                ->map(fn ($chargeId) => InvoiceItem::where('charge_id', $chargeId)->value('invoice_id'))
                ->filter()
                ->unique();

            foreach ($invoiceIds as $invoiceId) {
                $invoice = Invoice::find($invoiceId);
                if ($invoice && ! in_array($invoice->status, ['cancelled', 'credited'], true)) {
                    app(VoidInvoiceService::class)->void($invoice, 'CAM reconciliation voided');
                }
            }

            // Reset to pending so the pool basis unfreezes (once no allocation is billed) and the
            // allocation can be re-generated + re-billed with the corrected figures.
            $allocation->update([
                'status' => 'pending',
                'billed_charge_id' => null,
                'billed_credit_note_id' => null,
                'billed_admin_fee_charge_id' => null,
            ]);

            // Voiding an allocation re-opens the pool for correction: a sealed (reconciled) pool must
            // return to 'reconciling', otherwise generateAllocations — which is blocked on
            // 'reconciled' — can't re-run the corrected figures, and the operator is stuck re-billing
            // the stale allocation. (No separate un-reconcile action exists; this is that path.)
            $pool = CamExpensePool::find($allocation->cam_expense_pool_id);
            if ($pool && in_array($pool->status, ['reconciled', 'closed'], true)) {
                $pool->update(['status' => 'reconciling', 'reconciled_at' => null]);
            }

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
     *
     * @return array{0: Charge, 1: Invoice} the anchor charge + the recovery invoice it settled
     *   (returned so the caller can append the admin-fee line to the same document).
     */
    private function billChargeImmediately(CamAllocation $allocation, float $amount, int $year): array
    {
        /** @var Lease $lease */
        $lease = $allocation->lease;
        $now = CarbonImmutable::now();
        $name = "CAM Reconciliation — {$year}";

        // The recovery is additional consideration for the same taxable service supply as the monthly
        // CAM estimate (billed at 14%), so it carries the pool's configured recovery VAT (default 14%;
        // 0% for a genuinely non-taxable pass-through). VAT rides ON TOP of the cost recovery — the
        // net `$amount` (= allocated/true-up) is unchanged, so the books-check's Σ allocated tie-out holds.
        $vatRate = (float) ($allocation->pool->recovery_vat_rate ?? 0);
        $vat = round($amount * $vatRate / 100, 2);
        $lineTotal = round($amount + $vat, 2);

        // The Charge is a traceability record + the books-check anchor only — it is
        // ALREADY settled on the recovery invoice below. It MUST be is_active=false
        // and dated to the reconciled year so the monthly billing engine (which
        // loads only is_active charges) never picks it up and double-bills the
        // true-up onto the tenant's regular monthly invoice.
        $charge = Charge::create([
            'lease_id' => $lease->id,
            'name' => $name,
            'type' => 'other',
            'amount' => $amount,
            'currency' => 'EGP',
            'frequency' => 'one_time',
            'vat_applicable' => $vatRate > 0,
            'vat_rate' => $vatRate,
            'start_date' => CarbonImmutable::create($year, 1, 1),
            'end_date' => CarbonImmutable::create($year, 12, 31),
            'is_active' => false,
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
            'vat_amount' => $vat,
            'total' => $lineTotal,
            'paid_amount' => 0,
            'balance' => $lineTotal,
            'currency' => $lease->currency ?? 'EGP',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'charge_id' => $charge->id,
            'description' => $name,
            // 'cam_recovery' routes this to the dedicated CAM Recovery Revenue account
            // (إيرادات استرداد المصروفات المشتركة) in the GL, not generic misc income.
            'type' => 'cam_recovery',
            'amount' => $amount,
            'vat_rate' => $vatRate,
            'vat_amount' => $vat,
            'total' => $lineTotal,
        ]);

        return [$charge, $invoice];
    }

    /**
     * Bill the CAM admin fee. When $invoice is given (a positive true-up) the fee rides the
     * SAME recovery invoice as a second, VAT-bearing line; otherwise (negative/zero true-up)
     * it gets a dedicated fee-only invoice. Either way an is_active=false anchor charge is
     * created (traceability + books-check backing) and the invoice header is rebuilt from its
     * line items. The fee routes to cam_admin_fee_revenue in the GL via its item type.
     */
    private function billAdminFee(CamAllocation $allocation, float $fee, float $feeVat, int $year, ?Invoice $invoice): Charge
    {
        /** @var Lease $lease */
        $lease = $allocation->lease;
        $now = CarbonImmutable::now();
        $name = "CAM Admin Fee — {$year}";
        $lineTotal = round($fee + $feeVat, 2);

        // Derive the rate from the money that was actually computed, rather than reading the
        // current setting. $feeVat was calculated at RECONCILE time; if the operator changes the
        // standard rate before the allocation is billed, reading the setting here would stamp the
        // line with a rate its own VAT amount contradicts — and that line is what the tenant's
        // invoice, the credit note that reverses it, and the ETA payload all quote.
        $feeVatRate = $fee > 0 ? round($feeVat / $fee * 100, 2) : Vat::EXEMPT;

        // Anchor charge — is_active=false + dated to the reconciled year so the monthly
        // engine never re-bills it, exactly like the cost charge above.
        $charge = Charge::create([
            'lease_id' => $lease->id,
            'name' => $name,
            'type' => 'other',
            'amount' => $fee,
            'currency' => 'EGP',
            'frequency' => 'one_time',
            'vat_applicable' => true,
            'vat_rate' => $feeVatRate,
            'start_date' => CarbonImmutable::create($year, 1, 1),
            'end_date' => CarbonImmutable::create($year, 12, 31),
            'is_active' => false,
        ]);

        if ($invoice === null) {
            // Fee-only invoice (period = the reconciled CAM year, same rationale as the cost invoice).
            $invoice = Invoice::create([
                'lease_id' => $lease->id,
                'tenant_id' => $lease->tenant_id,
                'status' => 'issued',
                'issue_date' => $now,
                'due_date' => $now->addDays($lease->payment_terms_days ?? 7),
                'period_start' => CarbonImmutable::create($year, 1, 1),
                'period_end' => CarbonImmutable::create($year, 12, 31),
                'subtotal' => 0,
                'vat_amount' => 0,
                'total' => 0,
                'paid_amount' => 0,
                'balance' => 0,
                'currency' => $lease->currency ?? 'EGP',
            ]);
        }

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'charge_id' => $charge->id,
            'description' => $name,
            'type' => 'cam_admin_fee',
            'amount' => $fee,
            'vat_rate' => $feeVatRate,
            'vat_amount' => $feeVat,
            'total' => $lineTotal,
        ]);

        $this->rebuildInvoiceHeader($invoice);

        return $charge;
    }

    /**
     * Recompute the invoice header (subtotal / VAT / total) from its line items, then let
     * recomputeTotals() derive paid/balance/status. recomputeTotals() reads $this->total —
     * it does NOT sum the items — so the header must be set from items FIRST.
     */
    private function rebuildInvoiceHeader(Invoice $invoice): void
    {
        $items = $invoice->items()->get();
        $subtotal = round((float) $items->sum('amount'), 2);
        $vat = round((float) $items->sum('vat_amount'), 2);

        $invoice->update([
            'subtotal' => $subtotal,
            'vat_amount' => $vat,
            'total' => round($subtotal + $vat, 2),
        ]);

        $invoice->recomputeTotals();
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

    /**
     * A negative true-up becomes an issued credit on the tenant's account. It carries the pool's
     * recovery VAT — the tenant over-paid a 14%-VAT monthly estimate, so refunding the over-collection
     * must reverse that output VAT too (the credit-note journalizer books Dr Sales Returns + Dr VAT
     * Payable / Cr AR). A 0% pool → a VAT-free credit, byte-identical to before this setting existed.
     */
    private function billCredit(CamAllocation $allocation, float $credit, int $year): CreditNote
    {
        /** @var Lease $lease */
        $lease = $allocation->lease;

        $vatRate = (float) ($allocation->pool->recovery_vat_rate ?? 0);
        $vat = round($credit * $vatRate / 100, 2);
        $total = round($credit + $vat, 2);

        return CreditNote::create([
            'tenant_id' => $lease->tenant_id,
            'lease_id' => $lease->id,
            'status' => 'issued',
            'issue_date' => now(),
            'reason' => 'adjustment',
            'reason_notes' => "CAM reconciliation credit — {$year}",
            'subtotal' => $credit,
            'vat_amount' => $vat,
            'total' => $total,
            'applied_amount' => 0,
            'balance' => $total,
            'currency' => 'EGP',
        ]);
    }
}
