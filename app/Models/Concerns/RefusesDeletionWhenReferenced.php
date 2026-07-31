<?php

namespace App\Models\Concerns;

use App\Support\DeletionPolicy;
use DomainException;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Master data with history cannot be deleted — it is deactivated.
 *
 * **The standard this follows.** Yardi, MRI and Entrata all refuse to remove a record that carries
 * history: a tenant with ledger activity becomes *Past*, a vendor goes on *Hold*, a unit goes
 * *Down*. None of them offer "delete anyway with a warning", and the reason is worth stating —
 * the damage is not to the record being deleted. It is to every statement, report and audit trail
 * that referenced it, none of which are in front of the person clicking the button.
 *
 * A record with **no** history stays deletable. A vendor typed in twice, a unit created by mistake:
 * nothing is lost by removing it, and refusing would leave the operator with lists full of rubbish
 * they cannot clear. That is the whole distinction — not "is this important?" but "has anything
 * happened to it yet?".
 *
 * **The refusal names what is holding it**, with counts: "3 invoices, 1 lease". "Cannot delete this
 * tenant" alone leaves the operator hunting, and an operator who cannot find the blocker will find
 * a way around it instead.
 *
 * Soft-deleted children still count. A cancelled invoice is still history — it is exactly the thing
 * an auditor asks about — so a tenant whose only invoice was soft-deleted is still not a clean
 * record.
 */
trait RefusesDeletionWhenReferenced
{
    public static function bootRefusesDeletionWhenReferenced(): void
    {
        static::deleting(function (self $model): void {
            // Force-deleting a record that was already soft-deleted is a purge of something the
            // operator has already retired; the references were counted on the way in. The
            // method_exists guards are load-bearing: a WHEN_UNUSED model need not soft-delete
            // (AccountingPeriod does not), and isForceDeleting()/trashed() exist only on SoftDeletes.
            if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting()
                && method_exists($model, 'trashed') && $model->trashed()) {
                return;
            }

            $blockers = $model->deletionBlockers();

            if ($blockers === []) {
                return;
            }

            throw new DomainException(__('admin.errors.record_still_referenced', [
                'record' => class_basename(static::class),
                'blockers' => implode(', ', $blockers),
                'instead' => DeletionPolicy::insteadFor(static::class) ?? 'deactivate it instead',
            ]));
        });
    }

    /**
     * What currently points at this record, as "3 invoices, 1 lease".
     *
     * @return array<int, string>
     */
    public function deletionBlockers(): array
    {
        $blockers = [];

        foreach (DeletionPolicy::blockingRelationsFor(static::class) as $relation) {
            if (! method_exists($this, $relation)) {
                // The conformance test fails the build for this, so it cannot ship. Skipping
                // rather than throwing keeps a typo from blocking deletion of everything.
                continue;
            }

            $query = $this->{$relation}();

            // A soft-deleted child is still history — a cancelled invoice is precisely what an
            // auditor asks about — so count it.
            if (in_array(SoftDeletes::class, class_uses_recursive($query->getRelated()), true)) {
                $query = $query->withTrashed();
            }

            $count = $query->count();

            if ($count > 0) {
                $blockers[] = $count.' '.str($relation)->snake(' ')->plural($count)->toString();
            }
        }

        return $blockers;
    }

    /** Can this record be removed right now? Drives the UI so the button matches the outcome. */
    public function isDeletableNow(): bool
    {
        return $this->deletionBlockers() === [];
    }
}
