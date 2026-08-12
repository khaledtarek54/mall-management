<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * How the books are kept — the decisions that shape every period, not the money in them.
 */
class AccountingSettings extends Settings
{
    /**
     * The calendar month a fiscal year begins in. 1 = January.
     *
     * **This was hardcoded**, and `FiscalCalendar`'s own docblock admitted it: "a calendar year is
     * assumed (Jan–Dec); a fiscal year starting in another month is a future option." The reports
     * were already honest about it — they read `fiscal_years.starts_on` and only fall back to
     * 1 January when no row exists — so the data model always supported a July year and nothing
     * could create one.
     *
     * That is not a cosmetic gap. An entity on a July–June year would have every income statement,
     * every year-end close and every period-close gate running on somebody else's calendar, and the
     * only fix was a deploy. A fiscal year is a registration decision made once, by the accountant,
     * before the first entry is posted.
     *
     * **A fiscal year is named for the year it STARTS in.** `ensureYear(2026)` with a July start
     * means 1 July 2026 – 30 June 2027. That is the reading that leaves January-start installs
     * behaving exactly as before, which is what makes this safe to change under an existing
     * database — and the ambiguity is real enough to be worth stating rather than assuming.
     *
     * Changing it once periods carry posted entries is REFUSED, not warned about: it would re-date
     * periods that already have entries in them, so a document that was in an open period lands in
     * a closed one, or the reverse. See `App\Support\FiscalYearStart`.
     */
    public int $fiscal_year_start_month = 1;

    public static function group(): string
    {
        return 'accounting';
    }
}
