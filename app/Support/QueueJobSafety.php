<?php

namespace App\Support;

/**
 * Which queued jobs may run twice at once, and which must not — stated, not assumed.
 *
 * Two settings decide whether a job can be re-entered, and they live in different files, so nothing
 * connected them: a job's `$timeout` (how long it may legitimately run) and the connection's
 * `retry_after` (when the queue decides it died and hands it to another worker). When `retry_after`
 * is the smaller of the two, a slow job is handed out a SECOND time while the first is still
 * working — and the only symptom is load, so nobody looks.
 *
 * That is exactly what shipped. `ApplyLateFees` declared `$timeout = 600` against a `retry_after`
 * of 90, with no overlap guard, and swept the entire arrears backlog nightly at 04:00. Its sibling
 * `RunMonthlyBilling` had the identical timeout and the guard. One of the two was written second.
 *
 * So every job is classified here and `QueueJobSafetyConformanceTest` fails the build on an
 * unclassified one — a new job forces the decision rather than inheriting whichever default the
 * author had in mind. The gate also checks the arithmetic the two files could not check for each
 * other: no job's timeout may reach `retry_after`.
 */
final class QueueJobSafety
{
    /**
     * Jobs that must never run twice at once — each declares `WithoutOverlapping` in `middleware()`,
     * and the gate verifies it actually does.
     *
     * @var array<class-string, string>
     */
    public const SERIALISED = [
        \App\Jobs\ApplyLateFees::class =>
            'Sweeps the whole arrears backlog and raises money documents. Idempotent per invoice '.
            '(row lock + full precondition re-check), so a second run is not WRONG — it is double '.
            'the load and double the memory against AR at 04:00, on the one dataset that never shrinks.',

        \App\Jobs\RunMonthlyBilling::class =>
            'A manually-dispatched run racing the scheduled one would double-bill: the '.
            'already-billed existence check is not yet behind a DB unique constraint.',
    ];

    /**
     * Jobs where two concurrent runs are harmless, each with the reason.
     *
     * "Harmless" has to mean something specific: either the job is scoped to a single record whose
     * write is idempotent, or the worst case is a duplicate the recipient absorbs. It does not mean
     * "we have never seen it happen".
     *
     * @var array<class-string, string>
     */
    public const CONCURRENCY_SAFE = [
        \App\Jobs\SyncDocumentToLedger::class =>
            'Scoped to ONE document, and `LedgerPoster::sync()` is a reconciling upsert — it '.
            'compares the posted entry to the document and voids-and-reposts only on a difference. '.
            'Two runs converge on the same entry rather than making two.',

        \App\Jobs\BroadcastAnnouncement::class =>
            'Scoped to one announcement. The worst case is a duplicate notification, which is '.
            'noise rather than a wrong record, and it carries no timeout to outrun retry_after.',

        \App\Jobs\SendPushNotification::class =>
            'Scoped to one message to one device set. A duplicate push is absorbed by the '.
            'recipient; serialising every push behind a lock would cost far more than it saves.',

        \App\Jobs\SubmitInvoiceToEta::class =>
            'Scoped to one invoice, and the e-invoicing module is frozen — classified so the gate '.
            'stays complete, not touched.',
    ];

    /** @return array<int, class-string> */
    public static function classified(): array
    {
        return array_merge(array_keys(self::SERIALISED), array_keys(self::CONCURRENCY_SAFE));
    }
}
