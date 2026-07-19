<?php

namespace App\Services\OwnerAccounting;

use App\Models\OwnerStatement;
use App\Models\OwnerStatementRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Revise a FINALISED owner-statement run: supersede it (which makes its journalizer return
 * null, so the sweep VOIDS its GL entry — reusing the existing void self-heal, no bespoke
 * reversal) and finalise a fresh version (version + 1) computed from the current ledger.
 *
 * Net effect after the next sweep: the old accrual is voided and the corrected one is posted,
 * with a clean revision chain (`supersedes_id`).
 */
class ReviseOwnerStatementRunService
{
    public function __construct(private FinaliseOwnerStatementRunService $finalise) {}

    public function revise(OwnerStatementRun $run, User $actor, ?string $postingDate = null): OwnerStatementRun
    {
        return DB::transaction(function () use ($run, $actor, $postingDate) {
            $run = OwnerStatementRun::whereKey($run->id)->lockForUpdate()->firstOrFail();

            if (! $run->isFinalised()) {
                throw new \DomainException('Only a finalised owner-statement run can be revised.');
            }

            // Money-freeze guard. Revise rebuilds the statements from scratch (force-delete +
            // recreate at version+1), which orphans any disbursement already raised against the
            // superseded statements. For a PAID disbursement that is catastrophic: the fresh
            // statement starts paid_to_date = 0, so the overpayment cap (owner_share − paid_to_date,
            // recomputed only from THAT statement's paid disbursements) resets to the full share and
            // the owner can be paid a SECOND time. So refuse to revise while any non-cancelled
            // disbursement exists — cancel the open ones first; if the owner has already been paid,
            // the correction belongs in the next period, not a revision of the paid statement.
            if ($run->hasActiveDisbursements()) {
                throw new \DomainException(
                    'Cannot revise a run that has active disbursements — cancel the scheduled/approved payouts first. '
                    .'If the owner has already been paid, correct the difference in the next period rather than revising the paid statement.'
                );
            }

            // Retire the old version; the sweep voids its ledger entry (journalizer → null).
            $run->status = OwnerStatementRun::STATUS_SUPERSEDED;
            $run->save();
            $run->statements()->update(['status' => OwnerStatement::STATUS_SUPERSEDED]);

            // Finalise a fresh version: generate() sees the superseded latest → starts version + 1.
            return $this->finalise->finalise($run, $actor, $postingDate);
        });
    }
}
