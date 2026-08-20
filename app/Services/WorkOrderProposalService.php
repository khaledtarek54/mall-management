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
     * Approving a second quote replaces the estimate rather than adding to it: a revised quote is a
     * new answer to the same question, not extra work. Any other pending quote on the job is
     * withdrawn, because leaving two live approvals would make "what was agreed?" unanswerable.
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

            // Competing quotes for the same work cannot both stand.
            WorkOrderProposal::query()
                ->where('facility_work_order_id', $order->getKey())
                ->whereKeyNot($proposal->getKey())
                ->awaitingDecision()
                ->update([
                    'status' => WorkOrderProposal::STATUS_WITHDRAWN,
                    'decided_at' => now(),
                    'updated_at' => now(),
                ]);

            $order->forceFill([
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
