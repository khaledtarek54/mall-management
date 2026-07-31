<?php

namespace App\Models\Concerns;

use App\Support\DeletionPolicy;
use DomainException;

/**
 * The backstop behind removing the Delete button: a money record that has committed cannot be
 * deleted, from anywhere.
 *
 * Removing the UI action stops the operator. This stops everything else — a console command, a
 * future service, a relation-manager someone adds next year. `DeletionPolicy::NEVER_DELETABLE`
 * says which models, and the refusal names the correction path that replaces deletion rather than
 * just saying no.
 *
 * **Why it is committed-state aware rather than a blanket refusal.** Two legitimate paths delete
 * these models today and must keep working:
 *
 *   - `CreatePayment` deletes the orphan Payment row it just created when allocation fails — a
 *     rollback of a record that never became money;
 *   - `RecordAdvanceRepaymentService` reverses a repayment it has just logged.
 *
 * A blanket throw would break payment creation itself. So the rule is the one that actually
 * matters: **once the record is on the books, it is permanent.** An uncommitted draft can still be
 * rolled back, because nothing has happened yet that an auditor would need explained.
 *
 * `DomainException` deliberately — a refusal renders as a message and a redirect-back
 * (bootstrap/app.php), not a 500. The operator is told what to do instead.
 */
trait RefusesDeletionOfCommittedRecords
{
    public static function bootRefusesDeletionOfCommittedRecords(): void
    {
        static::deleting(function (self $model): void {
            if (! $model->isCommittedForDeletionPurposes()) {
                return;
            }

            $correction = DeletionPolicy::correctionFor(static::class) ?? 'correct it through its own workflow';

            throw new DomainException(__('admin.errors.record_not_deletable', [
                'record' => class_basename(static::class),
                'correction' => $correction,
            ]));
        });
    }

    /**
     * Has this record reached the books?
     *
     * Overridden per model where "committed" has a sharper meaning than "it exists" — see
     * Invoice (anything past draft) and Payment (money actually received).
     */
    public function isCommittedForDeletionPurposes(): bool
    {
        return true;
    }
}
