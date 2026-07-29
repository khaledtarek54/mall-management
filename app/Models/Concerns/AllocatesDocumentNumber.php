<?php

namespace App\Models\Concerns;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Serialise the allocation of a sequential document number across processes.
 *
 * **The bug this exists for.** Every numbered document in the system — Invoice, JournalEntry,
 * CreditNote, VendorBill, Expense, DepositTransaction, Payroll — allocated its number the same
 * way: read `MAX(number)` for the prefix, add one, insert. That is check-then-act across a
 * network round-trip, and the `->exists()` probe some of them add is not protection: it cannot see
 * another connection's UNCOMMITTED row. Two processes therefore compute the *same* number.
 * Demonstrated on the real database — two allocations with no insert between them both returned
 * `INV-AW-202607-0082`.
 *
 * It is reachable, not theoretical. Invoices alone are created by five services, and three take no
 * lock at all (`BillMeterReadingService`, `BillViolationFineService`, `CamReconciliationService`).
 * The nightly billing run generating invoices while an operator bills a violation fine, or while
 * `cam:reconcile` runs, is exactly the collision.
 *
 * **Why the consequence is an outage, not corruption.** All seven tables carry a UNIQUE index on
 * `number`, so the database refuses the second insert. Nothing silently wrong ends up in the
 * books — instead the loser gets a duplicate-key QueryException: a 500 for the operator, or a
 * scheduled billing job dying part-way through a month. The index is doing the job the code
 * should have been doing, and paying for it with availability.
 *
 * **The lock must span the INSERT, not just the read.** Locking only the calculation would leave
 * the same window: process A computes 0082, releases, and B computes 0082 before A's row lands.
 * So the lock is taken in `creating` and released in `created` — after the row exists and is
 * therefore visible to the next reader's `MAX()`.
 *
 * A wedged process cannot hold the lock forever: it is taken with a TTL, so the worst case is a
 * short wait rather than a permanently blocked document type. The UNIQUE index stays as the final
 * arbiter — this makes collisions not happen, it does not make the index redundant.
 */
trait AllocatesDocumentNumber
{
    /** Held between `creating` and `created` — see the class docblock for why it spans the insert. */
    protected ?Lock $documentNumberLock = null;

    public static function bootAllocatesDocumentNumber(): void
    {
        // Released once the row is committed and visible to the next MAX() read.
        static::created(function (self $model): void {
            $model->releaseDocumentNumberLock();
        });
    }

    /**
     * Run $generator with exclusive access to $lockKey, holding the lock until the insert lands.
     *
     * @param  string  $lockKey  the number PREFIX (e.g. `INV-AW-202607-`) — scoping the lock this
     *                           tightly means billing one property never blocks another, and
     *                           invoices never block journal entries.
     * @param  callable():string  $generator  the model's existing generateNumber()
     */
    protected function allocateDocumentNumber(string $lockKey, callable $generator): string
    {
        $lock = Cache::lock('doc-number:'.$lockKey, self::documentNumberLockSeconds());

        // block() WITHOUT a callback. Passing one makes Laravel release the lock in a `finally`
        // the moment the callback returns (Cache/Lock.php) — which would drop it before the
        // INSERT and reopen the exact window this exists to close. The first version of this
        // method did precisely that, and the "is a rival blocked mid-insert?" test caught it.
        //
        // Blocking rather than get(): a second writer should WAIT its turn and take the next
        // number, not fail.
        try {
            $lock->block(self::documentNumberLockWaitSeconds());
        } catch (LockTimeoutException) {
            // Degrade to the old unlocked behaviour rather than failing the document outright:
            // the UNIQUE index still refuses a genuine duplicate, so the worst case is what
            // happened before this trait existed, not a new failure mode.
            return $generator();
        }

        $this->documentNumberLock = $lock;

        return $generator();
    }

    public function releaseDocumentNumberLock(): void
    {
        $this->documentNumberLock?->release();
        $this->documentNumberLock = null;
    }

    /** TTL — long enough for an insert, short enough that a crashed process self-heals. */
    protected static function documentNumberLockSeconds(): int
    {
        return (int) config('app.document_number_lock_seconds', 10);
    }

    /** How long a second writer waits for its turn before degrading to unlocked allocation. */
    protected static function documentNumberLockWaitSeconds(): int
    {
        return (int) config('app.document_number_lock_wait_seconds', 5);
    }
}
