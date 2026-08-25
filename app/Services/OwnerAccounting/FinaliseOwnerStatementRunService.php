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
            // Idempotent — and the check has to happen HERE, before the regenerate.
            //
            // It used to sit AFTER it, where it was dead code: `generate()` refuses with "a
            // finalised statement already exists — revise it instead" the moment one does, so a
            // second `finalise()` on the same run RAISED rather than returning, while the line
            // below it called itself idempotent. Nothing ever double-posted, so this was a false
            // comment guarding an unreachable branch rather than a money defect (pre-staging QA,
            // F-13) — but a caller reading the code was told the opposite of what it did.
            //
            // Only THIS run short-circuits. A DRAFT run for a property and period that some OTHER
            // run has already finalised must still reach `generate()` and be refused there: that is
            // a different situation with a different remedy, and swallowing it would hide the one
            // case where the operator genuinely has to revise.
            $locked = OwnerStatementRun::whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isFinalised()) {
                return $locked->load('statements');
            }

            // Recompute the draft for this property + period from the live ledger, then freeze it.
            // (If $run was just superseded by Revise, generate() starts a fresh version.)
            $fresh = $this->generate->generate($run->asset, $run->accountingPeriod, $run->basis);

            $fresh = OwnerStatementRun::whereKey($fresh->id)->lockForUpdate()->firstOrFail();

            if (! $fresh->isDraft()) {
                throw new \DomainException('Only a draft owner-statement run can be finalised.');
            }

            // A statement with no owner is not a statement. `rebuildStatements()` distributes
            // nothing when the property has no owner whose tenure covers the period, the journalizer
            // skips a zero, and what is left is a finalised document addressed to nobody that posts
            // nothing — while the P&L underneath it shows real money the owner is owed. Refused
            // here rather than warned: the remedy (assign the property's owner, then regenerate) is
            // one screen away, and a finalised run is the thing a revision has to fight to undo.
            if ($fresh->statements()->count() === 0) {
                throw new \DomainException(__('admin.owner_statements.errors.no_owner', [
                    'property' => $fresh->asset?->name ?? '',
                ]));
            }

            // The ownership register must account for the WHOLE property before its net is
            // distributed. `GenerateOwnerStatementRunService` weights each owner `pct / Σ pct`, so
            // the shares always sum to the full net — right when every owner is recorded, and wrong
            // when they are not: one owner recorded at 50% has Σ = 50, weight 50/50 = 1, and takes
            // 100% of the net. `net_distributable` is then posted as Dr owner_distributions /
            // Cr due_to_owner and becomes the cap disbursements pay against, so a half-owner is
            // accrued — and payable — twice what they are owed.
            //
            // Enforced HERE rather than on the owners form, because a 50/50 register cannot be
            // built in one save: the first co-owner would be refused for totalling 50. The register
            // stays freely editable and the money path is what insists. Same shape and same reason
            // as the no-owner refusal above.
            $ownedPct = (float) $fresh->statements()->sum('ownership_percentage');
            if (abs($ownedPct - 100.0) > 0.01) {
                throw new \DomainException(__('admin.owner_statements.errors.ownership_not_whole', [
                    'property' => $fresh->asset?->name ?? '',
                    'total' => number_format($ownedPct, 2),
                ]));
            }

            // A PERIOD THAT HAS NOT ENDED CANNOT BE FROZEN (2026-08-25).
            //
            // Finalise re-reads the ledger and freezes the figures, and `net_distributable` is then
            // posted as Dr owner_distributions / Cr due_to_owner — which becomes the cap every
            // disbursement pays against. Freeze it before the last day of the period and the days
            // that follow are money the owner is owed, accrued nowhere and payable up to a cap that
            // already excludes them. Nothing on the screen says the month is unfinished, and the
            // remedy (Revise) is a manual act somebody has to remember.
            //
            // Measured on the demo portfolio: a run for DECEMBER finalised cleanly on 25 August —
            // a frozen document, addressed to the owner, about a month that had not started. The
            // August run finalised the same day, with six days of rent still to bill.
            //
            // The DRAFT stays free, exactly as it does for the two refusals above: generating one
            // mid-month is how an operator sees where the period is heading. And this is about the
            // STATEMENT, not about paying: an interim payout to an owner is a disbursement, which
            // has its own document and its own cap.
            if ($fresh->period_end->isFuture()) {
                throw new \DomainException(__('admin.owner_statements.errors.period_not_ended', [
                    'property' => $fresh->asset?->name ?? '',
                    'ends_on' => $fresh->period_end->toFormattedDateString(),
                ]));
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
