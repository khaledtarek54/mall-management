<?php

namespace App\Services\OwnerAccounting;

use App\Models\ApprovalRule;
use App\Models\Disbursement;
use App\Models\OwnerStatement;
use App\Models\User;
use App\Support\ApprovalPolicy;
use App\Support\PostingDate;
use App\Support\ReversalReason;
use Illuminate\Support\Facades\DB;

/**
 * The owner-payout lifecycle: schedule → approve → mark paid (+ cancel). Paying clears the
 * Due-to-Owner liability in the GL (DisbursementJournalizer). Partial payouts are allowed;
 * the running total is capped at the owner's share, re-checked UNDER LOCK at both schedule
 * and payment. The approval tier is frozen at schedule from ApprovalPolicy so a later amount
 * edit can't launder the authority that signed it off (procurement's F-99 shape).
 */
class DisbursementService
{
    /** Schedule a payout against a finalised owner statement (capped at the remaining balance). */
    public function schedule(
        OwnerStatement $statement,
        float $amount,
        string $method,
        User $actor,
        // Which bank account the money leaves — null means the rail decides, exactly as before.
        // Threaded through rather than read off the request, because the console and any future API
        // path build a disbursement here too.
        ?int $bankAccountId = null,
    ): Disbursement {
        return DB::transaction(function () use ($statement, $amount, $method, $bankAccountId) {
            $statement = OwnerStatement::whereKey($statement->id)->lockForUpdate()->firstOrFail();

            if (! in_array($statement->status, [OwnerStatement::STATUS_FINALISED, OwnerStatement::STATUS_SENT], true)) {
                throw new \DomainException(__('admin.refusals.disb_needs_finalised_run'));
            }

            $amount = round($amount, 2);
            if ($amount <= 0) {
                throw new \DomainException(__('admin.refusals.disb_amount_positive'));
            }

            $statement->recomputePaidToDate();
            $remaining = round((float) $statement->owner_share - (float) $statement->paid_to_date, 2);
            if ($amount > $remaining) {
                throw new \DomainException(__('admin.refusals.disb_exceeds_remaining', ['remaining' => $remaining]));
            }

            return $statement->disbursements()->create([
                'reference' => Disbursement::generateReference(),
                'asset_id' => $statement->asset_id,
                'user_id' => $statement->user_id,
                'amount' => $amount,
                'method' => $method,
                'bank_account_id' => $bankAccountId,
                // Freeze the required tier NOW (null when no ladder is configured for disbursements).
                'required_permission' => ApprovalPolicy::permissionFor(ApprovalRule::MODULE_DISBURSEMENT, $amount),
                'status' => Disbursement::STATUS_SCHEDULED,
            ]);
        });
    }

    /** Approve a scheduled disbursement (gated by the approval ladder + the FROZEN tier). */
    public function approve(Disbursement $disbursement, User $actor): Disbursement
    {
        return DB::transaction(function () use ($disbursement, $actor) {
            $disbursement = Disbursement::whereKey($disbursement->id)->lockForUpdate()->firstOrFail();

            if ($disbursement->status !== Disbursement::STATUS_SCHEDULED) {
                throw new \DomainException(__('admin.refusals.disb_approve_state'));
            }

            if (! ApprovalPolicy::canApprove($actor, ApprovalRule::MODULE_DISBURSEMENT, (float) $disbursement->amount)) {
                throw new \DomainException(__('admin.refusals.disb_approve_tier'));
            }
            // And the actor must still hold the tier that was required WHEN it was scheduled — so a
            // later re-band can't let a weaker approver clear a draw the policy reserved for a higher one.
            if ($disbursement->required_permission !== null && ! $actor->can($disbursement->required_permission)) {
                throw new \DomainException(__('admin.refusals.disb_approve_tier_lost'));
            }

            $disbursement->update([
                'status' => Disbursement::STATUS_APPROVED,
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
            ]);

            return $disbursement;
        });
    }

    /** Mark an approved disbursement paid — clears Due to Owner in the GL (via the sweep). */
    public function markPaid(
        Disbursement $disbursement,
        User $actor,
        string $paidOn,
        ?string $externalReference = null,
    ): Disbursement {
        return DB::transaction(function () use ($disbursement, $paidOn, $externalReference) {
            $disbursement = Disbursement::whereKey($disbursement->id)->lockForUpdate()->firstOrFail();

            if ($disbursement->status !== Disbursement::STATUS_APPROVED) {
                throw new \DomainException(__('admin.refusals.disb_pay_state'));
            }

            // Re-check the cap under lock — another disbursement may have been paid since scheduling.
            $statement = $disbursement->ownerStatement()->lockForUpdate()->firstOrFail();
            $statement->recomputePaidToDate();
            $remaining = round((float) $statement->owner_share - (float) $statement->paid_to_date, 2);
            if ((float) $disbursement->amount > $remaining) {
                throw new \DomainException(__('admin.refusals.disb_exceeds_remaining', ['remaining' => $remaining]));
            }

            // Money moved: not future, and the period must be open (guarded in the service).
            $date = PostingDate::assertNotFuture($paidOn, 'paid_on');

            $disbursement->update([
                'status' => Disbursement::STATUS_PAID,
                'paid_on' => $date->toDateString(),
                'external_reference' => $externalReference,
            ]);

            // The statement's paid_to_date now includes this payment (single source of truth).
            $statement->recomputePaidToDate();

            return $disbursement;
        });
    }

    /** Cancel a not-yet-paid disbursement (a paid one is corrected via a reversing entry, not cancelled). */
    public function cancel(Disbursement $disbursement, User $actor, ?string $reason = null): Disbursement
    {
        return DB::transaction(function () use ($disbursement, $reason) {
            $disbursement = Disbursement::whereKey($disbursement->id)->lockForUpdate()->firstOrFail();

            if ($disbursement->isPaid()) {
                throw new \DomainException(__('admin.refusals.disb_paid_no_cancel'));
            }
            if ($disbursement->status === Disbursement::STATUS_CANCELLED) {
                return $disbursement;
            }

            $disbursement->update(['status' => Disbursement::STATUS_CANCELLED]);

            // An owner's payout being called off is exactly the reversal an owner will ask about.
            // `disbursements` carries no `notes` column, so the trail is the whole record.
            ReversalReason::record($disbursement, 'cancelled', $reason);

            return $disbursement;
        });
    }
}
