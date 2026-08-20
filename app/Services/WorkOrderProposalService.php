<?php

namespace App\Services;

use App\Models\FacilityWorkOrder;
use App\Models\User;
use App\Models\WorkOrderProposal;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Submit, approve and refuse a contractor's quote — the before-the-money control.
 *
 * ServiceChannel §3: work expected to exceed the job's not-to-exceed amount needs an answer BEFORE
 * it happens. The three acts are a service rather than form writes for the same reason every other
 * controlled transition here is one — approving a quote commits the operator to a price, and it has
 * to refuse identically whether it arrives from a screen, the console or a future vendor portal
 * (gap O2).
 */
class WorkOrderProposalService
{
    /**
     * Record a quote against a job.
     *
     * Refused on a terminal work order: quoting for work that is finished or cancelled is a keying
     * error, and accepting it would let an approved proposal rewrite the estimate of a job whose
     * actuals are already in.
     */
    public function submit(FacilityWorkOrder $order, array $data, ?User $actor = null): WorkOrderProposal
    {
        if ($order->isTerminal()) {
            throw new DomainException(__('admin.facility.errors.proposal_order_terminal'));
        }

        $proposal = new WorkOrderProposal(array_merge($data, [
            'facility_work_order_id' => $order->getKey(),
            // Defaults to the contractor already on the job — the usual case, and one less thing
            // to get wrong — but a quote from somebody else is legitimate and may state its own.
            'vendor_id' => $data['vendor_id'] ?? $order->vendor_id,
            'status' => WorkOrderProposal::STATUS_SUBMITTED,
            'submitted_by_user_id' => ($actor ?? auth()->user())?->getKey(),
            'submitted_at' => now(),
        ]));

        if ((float) ($data['labour_amount'] ?? 0) + (float) ($data['material_amount'] ?? 0)
            + (float) ($data['service_amount'] ?? 0) <= 0) {
            // A quote for nothing is not a quote. Refused here rather than allowed through as a
            // zero, which would silently approve to an NTE of zero and read as "may spend nothing".
            throw new DomainException(__('admin.facility.errors.proposal_needs_amount'));
        }

        $proposal->save();

        return $proposal->refresh();
    }

    /**
     * **Approve it — and this is the moment the control does its work.**
     *
     * Two consequences, both deliberate:
     *
     * 1. **The NTE rises to the approved figure**, which is what "approval raises the NTE" means in
     *    the benchmark: the contractor may now spend up to what was agreed and no more. It is
     *    raised, never lowered — approving a quote BELOW an existing ceiling must not quietly
     *    tighten what the contractor was already permitted for other work on the same job.
     * 2. **The job's estimate is set from the quote's own buckets**, so the cost object's
     *    planned-vs-actual becomes "did the contractor deliver what they quoted?".
     *
     * **A quote is either the whole price or EXTRA on top of one already agreed**, and the two
     * behave differently — found by review, on the live database, after the first version treated
     * every quote as a replacement:
     *
     *     approved 38,000  → ceiling 38,000, estimate 38,000
     *     supplement 8,000 → ceiling stayed 38,000, estimate became 8,000
     *
     * …so the job read as 38,000 overspent. A `full` quote REPLACES (a revised price is a new
     * answer to the same question); a `supplementary` one ADDS to both, which is what happens when
     * a contractor opens a wall and finds more work.
     *
     * A full quote withdraws any other PENDING quote, because competing prices for the same work
     * cannot both stand. A supplementary one does not: two supplements for two different pieces of
     * extra work are not alternatives to each other.
     */
    public function approve(WorkOrderProposal $proposal, ?User $actor = null, ?string $reason = null): WorkOrderProposal
    {
        $this->assertPending($proposal);

        return DB::transaction(function () use ($proposal, $actor, $reason) {
            $order = $proposal->workOrder;

            $proposal->forceFill([
                'status' => WorkOrderProposal::STATUS_APPROVED,
                'decision_reason' => $reason,
                'decided_by_user_id' => ($actor ?? auth()->user())?->getKey(),
                'decided_at' => now(),
            ])->save();

            // Competing PRICES for the same work cannot both stand. Two supplements for two
            // different pieces of extra work are not alternatives, so they survive.
            if (! $proposal->is_supplementary) {
                WorkOrderProposal::query()
                    ->where('facility_work_order_id', $order->getKey())
                    ->whereKeyNot($proposal->getKey())
                    ->awaitingDecision()
                    ->update([
                        'status' => WorkOrderProposal::STATUS_WITHDRAWN,
                        'decided_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            $order->forceFill($proposal->is_supplementary
                ? [
                    // Extra work on top of what was already agreed.
                    'nte_amount' => (float) $order->nte_amount + (float) $proposal->total_amount,
                    'est_labour_cost' => (float) $order->est_labour_cost + (float) $proposal->labour_amount,
                    'est_material_cost' => (float) $order->est_material_cost + (float) $proposal->material_amount,
                    'est_service_cost' => (float) $order->est_service_cost + (float) $proposal->service_amount,
                ]
                : [
                    // A revised whole price. RAISED, never lowered — approving a cheaper revision
                    // must not tighten what the contractor was already permitted for.
                    'nte_amount' => max((float) $order->nte_amount, (float) $proposal->total_amount),
                    'est_labour_cost' => $proposal->labour_amount,
                    'est_material_cost' => $proposal->material_amount,
                    'est_service_cost' => $proposal->service_amount,
                ])->save();

            // `save()` fires `saving`, which re-derives est_total_cost — the estimate and its total
            // can never disagree, whichever road set them.
            return $proposal->refresh();
        });
    }

    /**
     * Refuse it. A reason is required: the contractor is being told not to proceed at this price,
     * and "rejected" with nothing written is the message that produces a phone call instead of a
     * revised quote.
     */
    public function reject(WorkOrderProposal $proposal, string $reason, ?User $actor = null): WorkOrderProposal
    {
        $this->assertPending($proposal);

        if (trim($reason) === '') {
            throw new DomainException(__('admin.facility.errors.proposal_needs_reason'));
        }

        $proposal->forceFill([
            'status' => WorkOrderProposal::STATUS_REJECTED,
            'decision_reason' => trim($reason),
            'decided_by_user_id' => ($actor ?? auth()->user())?->getKey(),
            'decided_at' => now(),
        ])->save();

        // Deliberately does NOT touch the job's NTE or estimate. A refusal changes nothing about
        // what was already agreed; it only says this price was not accepted.
        return $proposal->refresh();
    }

    private function assertPending(WorkOrderProposal $proposal): void
    {
        if ($proposal->status !== WorkOrderProposal::STATUS_SUBMITTED) {
            throw new DomainException(__('admin.facility.errors.proposal_already_decided'));
        }
    }
}
