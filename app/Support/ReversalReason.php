<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * **Why a money document was reversed, written where it cannot be edited afterwards.**
 *
 * Thirteen acts in this system undo a committed money document — void an invoice, void a receipt,
 * cancel a bill, cancel a payroll run, reverse a custody spend, un-apply a credit. On 2026-08-28
 * **five of the thirteen recorded a reason and eight recorded nothing**: `cancel_bill`,
 * `cancel_expense`, `cancel_payroll`, `cancel_deposit`, the disbursement cancel, both credit-note
 * acts, and the two invoice application-reversals all took a confirmation and threw the reason away
 * — most of them never asked for one at all.
 *
 * **A reversal without a reason is the one audit question nobody can answer later.** The reversing
 * entry proves the money moved back; it cannot say whether the receipt was keyed against the wrong
 * tenant, the bill was a duplicate, or somebody was hiding a mistake. Yardi, MRI and Entrata all
 * require a reason code on every reversal, and it is the first thing an auditor asks for.
 *
 * **The trail, never only `notes`.** `VoidInvoiceService` and `VoidPaymentService` already did both,
 * and the split is deliberate: `notes` is an ordinary editable column, so a reason kept only there
 * can be edited away by the same person who caused the reversal. `activity_log` cannot. Services
 * that stamp `notes` keep doing so — it is what shows on the document — but this is the record.
 *
 * **The log name is read off the MODEL** rather than passed in. Every audited model already declares
 * it once through `ActivityLogging::for($this, '…')`, and a helper that took its own string would let
 * a reversal file under `payments` while every other row for that model files under `payment` —
 * invisible until someone filters the activity feed by log name and finds half the history missing.
 *
 * **The description is a KEY**, per the house rule that a row stores DATA and never PROSE: one
 * wording fix then reaches rows written years ago, and the same stored row reads correctly in
 * Arabic and in English. Keys live under `admin.activity.descriptions.{log}.{event}` and
 * `ActivityLogVocabularyConformanceTest` requires both languages.
 */
final class ReversalReason
{
    /**
     * Record that a document was reversed, and why.
     *
     * @param  string  $event  the activity event — `voided`, `cancelled` or `reversed`. Three words
     *                         because they are three acts (see `Payment::REVERSED_STATUSES`), and
     *                         flattening them would make the audit feed unable to tell a receipt
     *                         keyed in error from a cheque the bank returned.
     */
    public static function record(Model $document, string $event, ?string $reason): void
    {
        $log = self::logNameFor($document);

        activity($log)
            ->performedOn($document)
            ->event($event)
            // array_filter drops a null reason rather than storing `{"reason":null}`, which reads on
            // screen as a reason that was given and was empty.
            ->withProperties(array_filter(['reason' => $reason]))
            ->log($log.'.'.$event);
    }

    /**
     * Append the reason to the document's own `notes`, the way the two services that got this right
     * already do — so it is visible on the document itself, not only in the audit feed.
     *
     * Returns the new notes value; the CALLER saves. It cannot save here: every one of these
     * services is mid-transaction with a locked row and its own status change to write, and a second
     * save would fire the model hooks twice.
     */
    public static function stamp(?string $notes, ?string $reason, string $marker = '[VOID]'): ?string
    {
        if (! filled($reason)) {
            return $notes;
        }

        return trim(($notes ? $notes."\n" : '').$marker.' '.$reason);
    }

    /**
     * The log name this model's OTHER activity rows are filed under — read from its own
     * `getActivitylogOptions()`, which is where `ActivityLogging::for()` put it.
     */
    private static function logNameFor(Model $document): string
    {
        if (method_exists($document, 'getActivitylogOptions')) {
            $declared = $document->getActivitylogOptions()->logName ?? null;

            if (filled($declared)) {
                return (string) $declared;
            }
        }

        // A model with no declared log name would otherwise land in spatie's `default` bucket, which
        // renders as "Other" and is invisible to the log-name filter — the failure CLAUDE.md warns
        // about for bare `activity()` calls. The morph alias is at least the model's own identity.
        return $document->getMorphClass();
    }
}
