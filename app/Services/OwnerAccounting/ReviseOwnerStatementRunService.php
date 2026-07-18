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

            // Retire the old version; the sweep voids its ledger entry (journalizer → null).
            $run->status = OwnerStatementRun::STATUS_SUPERSEDED;
            $run->save();
            $run->statements()->update(['status' => OwnerStatement::STATUS_SUPERSEDED]);

            // Finalise a fresh version: generate() sees the superseded latest → starts version + 1.
            return $this->finalise->finalise($run, $actor, $postingDate);
        });
    }
}
