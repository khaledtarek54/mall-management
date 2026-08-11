<?php

namespace App\Support;

use App\Models\OwnerStatementRun;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Has a month already been REPORTED to someone outside the building?
 *
 * The gap this names. `AccountingPeriod` has two states, open and closed, and the close gate stops
 * you sealing a month over a pending re-post. Nothing stops the opposite: **changing a month you
 * have already reported but not yet closed.** An owner statement can be finalised on the 5th and the
 * period closed on the 20th, and in those fifteen days an edit to a March document silently voids
 * its entry and posts a new one — restating a figure the owner has already been given.
 *
 * **What Yardi does, and why this warns rather than refuses.** Voyager has no "reported" state; its
 * control is the post month, and the discipline is that you CLOSE the month when you report it.
 * A month that has been reported and left open is a process gap, not a transaction to refuse — so
 * this surfaces the gap (on the document, in the month-end checklist, and to the GL managers when a
 * restatement actually happens) and steers to closing the period, which is the control that already
 * exists. Refusing the change outright would be **stricter than Yardi**, and would block the
 * legitimate case where the correction is exactly what the owner is waiting for.
 *
 * **Derived, not stored.** A month is reported because a finalised owner-statement run covers it —
 * there is no `reported_at` column to set, forget, or drift out of step with the statements
 * themselves. One question, one query, no second source of truth.
 */
class ReportedPeriod
{
    /**
     * True when a finalised owner statement covers this date's month.
     *
     * `$assetId` narrows it to one property's books: an owner statement for Mall A says nothing
     * about whether Mall B's March has been reported, and treating it as if it did would warn on
     * every correction in the portfolio.
     */
    public static function isReported(DateTimeInterface|string|null $date, ?int $assetId = null): bool
    {
        return self::runFor($date, $assetId) !== null;
    }

    /** A human sentence naming the statement that reported this month, or null when none has. */
    public static function reasonFor(DateTimeInterface|string|null $date, ?int $assetId = null): ?string
    {
        $run = self::runFor($date, $assetId);

        if (! $run) {
            return null;
        }

        return __('admin.ledger_trail.reported_by', [
            'reference' => $run->reference,
            'date' => $run->finalised_at?->format('d/m/Y') ?? '',
        ]);
    }

    /** The finalised run covering this month, if there is one. */
    public static function runFor(DateTimeInterface|string|null $date, ?int $assetId = null): ?OwnerStatementRun
    {
        if ($date === null) {
            return null;
        }

        $month = CarbonImmutable::parse($date);
        $start = $month->startOfMonth()->toDateString();
        $end = $month->endOfMonth()->toDateString();

        return OwnerStatementRun::query()
            ->where('status', OwnerStatementRun::STATUS_FINALISED)
            // Overlap, not containment: a statement covering a quarter reports each of its months.
            ->whereDate('period_start', '<=', $end)
            ->whereDate('period_end', '>=', $start)
            ->when($assetId !== null, fn ($q) => $q->where('asset_id', $assetId))
            ->orderByDesc('finalised_at')
            ->first();
    }
}
