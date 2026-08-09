<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Where a document's GL entry lands, when that is not the document's own month (story MF-05).
 *
 * **Document date and post month are different facts.** A February vendor bill that arrives after
 * February closes is still a February bill — the vendor invoiced it then, the tenant sees that date,
 * the ETA payload carries it. What has to move is the *books*. Yardi carries both on every
 * transaction and runs its reports on the post month (02-yardi-money-flow.md); this is that
 * separation, expressed as an override on the entry rather than a second date on 24 tables.
 *
 * **Consulted once, in `LedgerPoster`, where every payload is built.** That is deliberate: a post
 * month implemented source-by-source would be half-done for a long time, and an operator cannot tell
 * by looking which documents obey it. Here it either works for all 24 GL sources or for none.
 *
 * **The day is preserved, the month is replaced.** A bill dated 28 February posted to March lands on
 * 28 March, so entries keep their relative order inside the month. A day the target month does not
 * have (31 → February) clamps to its last day rather than rolling into the next month, which would
 * defeat the whole point by landing in a period the operator did not choose.
 */
class PostMonth
{
    /** The month a document's entry should land in, or null when it posts to its own date. */
    public static function forSource(?Model $source): ?CarbonImmutable
    {
        if (! $source instanceof Model || ! $source->exists) {
            return null;
        }

        $month = DB::table('posting_month_overrides')
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->value('post_month');

        return $month ? CarbonImmutable::parse($month)->startOfMonth() : null;
    }

    /**
     * The effective GL date for a document: its own date, moved into the post month if one is set.
     *
     * Returns the input untouched when there is no override, so every source that never uses this
     * behaves exactly as it did.
     */
    public static function resolve(?Model $source, mixed $documentDate): mixed
    {
        $month = self::forSource($source);

        if ($month === null || blank($documentDate)) {
            return $documentDate;
        }

        $date = CarbonImmutable::parse($documentDate);

        // Clamp rather than roll: 31 January posted to February is 28/29 February, never 2 March.
        $day = min($date->day, $month->daysInMonth);

        return $month->setDay($day)->toDateString();
    }

    /** Has this document been moved out of its own month? */
    public static function isOverridden(?Model $source): bool
    {
        return self::forSource($source) !== null;
    }
}
