<?php

namespace App\Services;

use App\Models\MaintenancePenalty;
use App\Models\MaintenanceWorkOrder;
use App\Models\VendorContract;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Assesses the SLA penalty owed by an external maintenance company (FR-CM-08).
 *
 * The FRD says only "automatically flag and calculate a penalty when a CM request exceeds
 * its configured SLA duration" — never on what basis. The three plausible readings (a flat
 * fee, a per-day accrual, a share of the job's value) behave differently enough that
 * guessing one and being wrong is a rewrite, not a tweak. So the basis is **configuration on
 * the vendor's contract**, and all three are supported.
 *
 * **Why re-assessment rather than a one-shot.** A per-day penalty grows while the job stays
 * late, so it cannot be computed once. The obvious key — `sla_breach_notified_at` — is a
 * once-per-record stamp for the alert and would either charge once or double-charge on
 * re-run. Instead there is ONE penalty row per work order (a DB unique), and each scan
 * **updates** it. The amount is frozen when the job reaches a terminal state.
 *
 * Only external jobs are penalised: FR-CM-08 is a contractual remedy against the company
 * that missed its SLA. An in-house job that runs late is flagged, not billed to anyone.
 */
class AssessSlaPenaltyService
{
    /**
     * Create or update the penalty for a breaching order. Returns null when no penalty
     * applies — which is the common case, not an error.
     */
    public function assess(MaintenanceWorkOrder $order): ?MaintenancePenalty
    {
        return DB::transaction(function () use ($order) {
            /** @var MaintenanceWorkOrder $locked */
            $locked = MaintenanceWorkOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            $existing = MaintenancePenalty::where('maintenance_work_order_id', $locked->getKey())->first();

            // A waived penalty is an operator's decision. Never resurrect it — the scan runs
            // hourly and would otherwise silently undo the waiver on the next tick.
            if ($existing?->isWaived()) {
                return $existing;
            }

            // Frozen: the job is closed, so the elapsed time can no longer change.
            if ($existing && ! $existing->isPending()) {
                return $existing;
            }

            $contract = $this->contractFor($locked);

            if (! $this->isPenalisable($locked, $contract)) {
                return $existing;
            }

            // Gate on ACTUAL lateness (any positive overrun, even sub-hour) — NOT on
            // hoursOverSla() > 0, which truncates a sub-hour breach to 0 and let a job minutes
            // late escape a penalty forever (it goes terminal, and the scan only sees open jobs).
            if (! $locked->isSlaBreached()) {
                return $existing;
            }

            $hoursOver = $locked->hoursOverSla(); // informational, frozen onto the row
            $amount = $this->amountFor($locked, $contract);

            if ($amount === null) {
                return $existing;
            }

            $attributes = [
                'asset_id' => $locked->asset_id,
                'vendor_id' => $locked->vendor_id,
                'vendor_contract_id' => $contract->id,
                'basis' => $contract->sla_penalty_basis,
                'rate' => $contract->sla_penalty_rate,
                'hours_over_sla' => $hoursOver,
                'amount' => $amount,
                // Frozen the moment the job stops running late — after that the elapsed
                // time is history, and an accruing penalty must stop growing.
                'status' => $locked->isTerminal() ? MaintenancePenalty::STATUS_FINAL : MaintenancePenalty::STATUS_PENDING,
                'finalised_at' => $locked->isTerminal() ? now() : null,
            ];

            if ($existing) {
                $existing->update($attributes);

                return $existing->refresh();
            }

            return MaintenancePenalty::create(array_merge(
                ['maintenance_work_order_id' => $locked->getKey()],
                $attributes,
            ));
        });
    }

    /**
     * An operator decides not to charge it — the vendor called ahead, the part was on
     * back-order, the breach was the mall's fault. Terminal: the scan never revives it.
     *
     * **Waiving an APPLIED penalty must un-deduct it first.** Waiving is "we are not charging
     * this after all", so the vendor's bill has to go back to owing the full amount. This
     * used to only flip the status: `vendor_bill_id` stayed set and the bill was never
     * recomputed, so its balance kept the deduction — while the real-time GL sync saw the
     * journalizer return null (it only posts an APPLIED penalty) and correctly VOIDED the
     * entry, restoring AP. The bill said 9,000 and the ledger said 10,000: the AP tie-out
     * broke by exactly the penalty and the vendor was underpaid. `detach()` had it right all
     * along — this is the path that forgot (fixed 2026-07-16).
     *
     * @throws DomainException if already waived
     */
    public function waive(MaintenancePenalty $penalty, string $reason, ?int $actorId = null): MaintenancePenalty
    {
        return DB::transaction(function () use ($penalty, $reason, $actorId) {
            /** @var MaintenancePenalty $locked */
            $locked = MaintenancePenalty::whereKey($penalty->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isWaived()) {
                throw new DomainException(__('admin.preventive_maintenance.errors.penalty_already_waived'));
            }

            // Captured before the update, because clearing vendor_bill_id loses the link.
            $bill = $locked->isApplied() ? $locked->bill : null;

            $locked->update([
                'status' => MaintenancePenalty::STATUS_WAIVED,
                // Release the bill: a waived penalty is not deducted from anything, and
                // leaving the FK set would keep it in VendorBill::recompute()'s sum.
                'vendor_bill_id' => null,
                'applied_at' => null,
                'waived_at' => now(),
                'waived_by_user_id' => $actorId ?? auth()->id(),
                'waive_reason' => $reason,
            ]);

            // recompute() is the ONLY thing allowed to write a bill's balance — mirrors
            // detach(). Restores the amount the waiver just gave back to the vendor.
            $bill?->refresh()->recompute();

            return $locked->refresh();
        });
    }

    /**
     * The contract whose SLA terms govern this job: the vendor's contract covering this
     * property and in force on the day the work was scheduled. A vendor may hold several
     * over time, and a penalty must be judged by the terms in force **then** — not by
     * whatever was signed later.
     *
     * Three subtleties, each of which was a bug first:
     *
     *  - A contract may be **portfolio-wide** (`asset_id` null) — the UI offers exactly that
     *    and shows such contracts to every property. Demanding an exact asset match let a
     *    vendor on a portfolio SLA escape penalties entirely.
     *  - A **property-specific** contract outranks a portfolio-wide one for the same vendor:
     *    the more specific agreement is the one that was negotiated for this mall.
     *  - `draft` and `terminated` contracts levy nothing — one was never in force, the other
     *    no longer is. `expired` is deliberately still eligible: `vendors:expire-contracts`
     *    flips that status once `end_date` passes, so excluding it would retroactively erase
     *    the penalty on a job that ran while the contract was live. The **date window** is
     *    what judges history; the status only rules out agreements that never applied.
     */
    private function contractFor(MaintenanceWorkOrder $order): ?VendorContract
    {
        if ($order->vendor_id === null) {
            return null;
        }

        $on = $order->scheduled_for?->toDateString() ?? now()->toDateString();

        return VendorContract::query()
            ->where('vendor_id', $order->vendor_id)
            ->where(fn ($q) => $q->where('asset_id', $order->asset_id)->orWhereNull('asset_id'))
            ->whereNotIn('status', ['draft', 'terminated'])
            ->where('sla_penalty_basis', '!=', 'none')
            ->whereDate('start_date', '<=', $on)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $on))
            // Property-specific first, then the most recently started.
            ->orderByRaw('asset_id IS NULL')
            ->orderByDesc('start_date')
            ->first();
    }

    private function isPenalisable(MaintenanceWorkOrder $order, ?VendorContract $contract): bool
    {
        return $contract !== null
            && $order->isCorrective()
            // FR-CM-08 is a remedy against the company that missed its SLA. An in-house job
            // running late is a management problem, not a billable one.
            && $order->execution_type === MaintenanceWorkOrder::EXECUTION_EXTERNAL
            && $order->vendor_id !== null
            && $order->target_resolution_at !== null
            && (float) $contract->sla_penalty_rate > 0;
    }

    /** Null when the basis cannot be computed for this job — see percent_of_value. */
    private function amountFor(MaintenanceWorkOrder $order, VendorContract $contract): ?float
    {
        $rate = (float) $contract->sla_penalty_rate;

        return match ($contract->sla_penalty_basis) {
            // Flat fires on ANY breach (the gate already proved lateness) — never gated on hours.
            MaintenancePenalty::BASIS_FLAT => round($rate, 2),

            // Part of a day counts as a day (from the TRUE elapsed time via daysOverSla — at least
            // 1 for any breach): charging 0.4 of a day for a nine-hour overrun invites an argument.
            MaintenancePenalty::BASIS_PER_DAY => round((float) $order->daysOverSla() * $rate, 2),

            // Needs the job's value. Null (no quote captured) means it cannot be computed —
            // return null rather than silently charge 0, which would look like "assessed and
            // owed nothing" instead of "we don't know yet".
            MaintenancePenalty::BASIS_PERCENT_OF_VALUE => $order->job_value === null
                ? null
                : round((float) $order->job_value * $rate / 100, 2),

            default => null,
        };
    }
}
