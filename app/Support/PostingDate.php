<?php

namespace App\Support;

use App\Models\AccountingPeriod;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * Guards a user-supplied date that a general-ledger entry will be dated from.
 *
 * WHY THIS EXISTS. Several documents let an operator type the date the money actually
 * moved, and their journalizer uses that date as the entry's `entry_date` — which decides
 * the accounting period it lands in. Nothing checked that the period was still open, and
 * the failure was silent in the worst possible way:
 *
 *   1. the service commits the row (outstanding drops, the operator sees "Recorded ✓")
 *   2. the journalizer builds a payload dated into the closed period
 *   3. JournalPostingService::assertOpenPeriodFor() throws
 *   4. ...inside the queued SyncDocumentToLedger job, which is deliberately best-effort
 *      and LOGS the failure rather than retrying
 *
 * So business state moved, the GL didn't, and the operator was told it worked — the exact
 * shape of the MaintenancePenalty bug. The sweep does eventually surface it, but only as an
 * opaque `ledger_last_sync_failures` count, to a different role, later. (Found 2026-07-16 as
 * gap-analysis F-93/F-89; module 25 rated 🔴 because for a عهدة, receipts arriving after a
 * month-end close is the NORMAL workflow, not an edge case.)
 *
 * **Refuse rather than silently re-date.** A real ERP would separate document date from
 * posting date and post the correction into the next open period; Atriom has no such
 * concept, and inventing one here would quietly file the money in a month the operator
 * didn't choose. Refusing is honest: it tells them the period is closed and lets them
 * reopen it or re-date deliberately. If that proves too blunt in practice, the fix is a
 * posting-date concept, not a looser guard.
 *
 * Use in the SERVICE, not just the form: a DatePicker's minDate/maxDate is UX, and the API
 * and console paths never see it.
 */
class PostingDate
{
    /**
     * Assert a date will not be rejected by the ledger, and normalise it.
     *
     * **A missing period is deliberately NOT an error.** Only a CLOSED period is. The two look
     * similar and are opposites:
     *
     *  - *closed* means the GL is live, tracks this date, and has sealed it — a document dated
     *    there can never post, so accepting the write is the divergence this class exists to
     *    stop.
     *  - *no period* means the GL simply isn't configured for that date (a fresh install, or
     *    accounting not adopted yet). Nothing can diverge from books that aren't keeping
     *    score, the write is harmless, and `SyncLedgerCommand::ensureFiscalYears()` opens the
     *    year on the next sweep — which then posts it.
     *
     * Throwing on a missing period looked defensible and was wrong: it refused legitimate
     * custody settlements on any install without a chart of accounts. The full suite caught it
     * (`CustodyResourceTest`, `EmployeeAdvancesRelationManagerTest` — three tests that seed
     * only roles), which is the argument for running it rather than just the new tests.
     *
     * @throws DomainException when the date is missing, or its period is closed
     */
    public static function assertOpen(mixed $date, string $field = 'date'): Carbon
    {
        $parsed = self::parse($date);

        if ($parsed === null) {
            throw new DomainException(__('admin.posting.errors.date_missing', ['field' => $field]));
        }

        $period = AccountingPeriod::forDate($parsed);

        // No period → the GL isn't tracking this date. Nothing to protect; let the write through.
        if ($period && ! $period->isOpen()) {
            throw new DomainException(__('admin.posting.errors.period_closed', [
                'month' => $parsed->format('Y-m'),
            ]));
        }

        return $parsed;
    }

    /**
     * Is this date's accounting period SEALED?
     *
     * The question `assertOpen()` answers as a refusal, asked as a boolean — for callers that must
     * branch rather than throw, like the forward-only re-derivation of straight-line rent, which
     * skips a closed month instead of failing the whole run. Same rule, one definition: a MISSING
     * period is not closed (see `assertOpen()` for why the two are opposites).
     */
    public static function isClosed(mixed $date): bool
    {
        $parsed = self::parse($date);

        if ($parsed === null) {
            return false;
        }

        $period = AccountingPeriod::forDate($parsed);

        return $period !== null && ! $period->isOpen();
    }

    /**
     * Assert a date is not in the future. Money that has not moved yet cannot be recorded as
     * having moved — and a future date posts into a period that will later close around it.
     *
     * @throws DomainException
     */
    public static function assertNotFuture(mixed $date, string $field = 'date'): Carbon
    {
        $parsed = self::assertOpen($date, $field);

        if ($parsed->isAfter(now()->endOfDay())) {
            throw new DomainException(__('admin.posting.errors.future'));
        }

        return $parsed;
    }

    private static function parse(mixed $date): ?Carbon
    {
        if ($date === null || $date === '') {
            return null;
        }

        if ($date instanceof \DateTimeInterface) {
            return Carbon::instance($date);
        }

        try {
            return Carbon::parse((string) $date);
        } catch (\Throwable) {
            return null;
        }
    }
}
