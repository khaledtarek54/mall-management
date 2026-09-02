<?php

namespace App\Support;

/**
 * The one wording of "money this statement is NOT showing, because it is filed against no property"
 * (EG-27).
 *
 * There are FOUR renderers of that sentence — the screen, the CSV export, the scheduled email's
 * copy of that CSV, and the PDF — and they interpolated the same three placeholders in three
 * separate places. That is not a tidiness point: on 2026-09-02 the screen and the CSV were taught to
 * count the population their own statement shows, the PDF was not, and one income statement went out
 * of the building quoting **134,300** while the screen the operator pressed the button on said
 * **84,300**. A consistent overstatement is diagnosable; two copies of one statement disagreeing is
 * worse, and the PDF is the copy an auditor reads.
 *
 * `cumulative` picks the tense, and it is DERIVED from the window rather than declared: a balance
 * sheet is an *as at* statement and reads everything up to its date, so "This period holds 47
 * entries" claims a span it never read.
 */
class UnallocatedNotice
{
    public static function heading(): string
    {
        return __('admin.journal_entries.unallocated.heading');
    }

    /**
     * @param  array{count: int, total: float, cumulative?: bool}  $notice
     */
    public static function sentence(array $notice): string
    {
        return __('admin.journal_entries.unallocated.'.(($notice['cumulative'] ?? false) ? 'body_as_at' : 'body'), [
            'count' => number_format((int) $notice['count']),
            'total' => number_format((float) $notice['total'], 2),
            'currency' => config('app.currency', 'EGP'),
        ]);
    }

    /** Heading and sentence as one line — the shape a CSV cell and a toast need. */
    public static function line(array $notice): string
    {
        return self::heading().' — '.self::sentence($notice);
    }
}
