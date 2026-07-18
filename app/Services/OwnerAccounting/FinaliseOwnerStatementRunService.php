<?php

namespace App\Services\OwnerAccounting;

use App\Models\OwnerStatement;
use App\Models\OwnerStatementRun;
use App\Models\User;
use App\Support\PostingDate;
use Illuminate\Support\Facades\DB;

/**
 * Finalise a DRAFT owner-statement run: recompute its figures from the current ledger
 * (freeze the truth AS OF finalisation), stamp the posting date, and flip it to finalised.
 *
 * It does NOT post the GL synchronously — saving the run finalised fires the realtime-sync
 * hook, and the `accounting:sync-ledger` sweep backstops it (the house pattern; avoids
 * posting inside the mutating transaction). The posting date is guarded by App\Support\PostingDate
 * in the SERVICE (a closed period is refused; a missing period is allowed and posts on the next
 * sweep once the fiscal year opens).
 */
class FinaliseOwnerStatementRunService
{
    public function __construct(private GenerateOwnerStatementRunService $generate) {}

    public function finalise(OwnerStatementRun $run, User $actor, ?string $postingDate = null): OwnerStatementRun
    {
        return DB::transaction(function () use ($run, $actor, $postingDate) {
            // Recompute the draft for this property + period from the live ledger, then freeze it.
            // (If $run was just superseded by Revise, generate() starts a fresh version.)
            $fresh = $this->generate->generate($run->asset, $run->accountingPeriod, $run->basis);

            $fresh = OwnerStatementRun::whereKey($fresh->id)->lockForUpdate()->firstOrFail();

            if ($fresh->isFinalised()) {
                return $fresh->load('statements'); // idempotent — already finalised
            }
            if (! $fresh->isDraft()) {
                throw new \DomainException('Only a draft owner-statement run can be finalised.');
            }

            // Guard the posting date against a closed period, in the service (not just the form).
            $date = PostingDate::assertOpen($postingDate ?? $fresh->period_end->toDateString(), 'posting_date');

            $fresh->posting_date = $date->toDateString();
            $fresh->status = OwnerStatementRun::STATUS_FINALISED;
            $fresh->finalised_at = now();
            $fresh->finalised_by_user_id = $actor->id;
            $fresh->save();

            $fresh->statements()->update(['status' => OwnerStatement::STATUS_FINALISED]);

            return $fresh->load('statements');
        });
    }
}
