<?php

namespace App\Support;

use App\Models\JournalEntry;
use App\Settings\AccountingSettings;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * The month a fiscal year begins in, and the one rule about changing it.
 *
 * `FiscalCalendar` hardcoded January, and said so in its own docblock: *"a calendar year is assumed
 * (Jan–Dec); a fiscal year starting in another month is a future option."* The reports were already
 * honest — they read `fiscal_years.starts_on` and fall back to 1 January only when no row exists —
 * so the data model always supported a July year and nothing could produce one. An Egyptian entity
 * on a July–June year would run every statement, close and period gate on somebody else's calendar,
 * fixable only by a deploy.
 *
 * ## Changing it is refused once anything is posted, not warned about
 *
 * A fiscal year's start month is not a preference; it re-dates the PERIODS. Move it from January to
 * July and the period that held March's entries either shifts or is rebuilt around them, so a
 * document posted into an open period can land inside a closed one — or, worse, the reverse: an
 * entry in a period the accountant has closed and reported becomes editable again.
 *
 * There is no safe migration of posted history, and a warning that an operator can click through is
 * not a guard. This is a registration decision made once, by the accountant, before the first entry
 * — which is exactly when it is free to make. {@see assertChangeable()} enforces that.
 *
 * ## Named for the year it STARTS in
 *
 * `ensureYear(2026)` with a July start means 1 July 2026 – 30 June 2027. Stated because the
 * convention genuinely varies and both readings are defensible; this one is chosen because it
 * leaves every January-start install behaving byte-identically, which is what makes the setting
 * safe to introduce under a live database.
 */
class FiscalYearStart
{
    /** The configured start month, clamped to a real one. */
    public static function month(): int
    {
        $month = (int) app(AccountingSettings::class)->fiscal_year_start_month;

        // Clamped rather than thrown: a mistyped month must not stop the accounting calendar from
        // being buildable at all, and January is where it started.
        return ($month >= 1 && $month <= 12) ? $month : 1;
    }

    /** Is the books' year the plain calendar year? */
    public static function isCalendarYear(): bool
    {
        return self::month() === 1;
    }

    /**
     * Refuse a change that would re-date periods already carrying posted entries.
     *
     * Deliberately keyed on POSTED entries rather than on the existence of a fiscal year: an
     * install that ran `atriom:install` has a calendar seeded and nothing in it, and that operator
     * must still be able to say "our year starts in July" — which is the whole point of the
     * setting, and the moment they are most likely to do it.
     */
    public static function assertChangeable(int $to): void
    {
        if ($to === self::month()) {
            return;
        }

        if (JournalEntry::query()->where('status', 'posted')->exists()) {
            throw new DomainException(__('admin.errors.fiscal_year_start_locked'));
        }
    }

    /** @return array<int, string> month number => month name, for a picker */
    public static function options(): array
    {
        return collect(range(1, 12))
            ->mapWithKeys(fn (int $m) => [$m => CarbonImmutable::create(2026, $m, 1)->translatedFormat('F')])
            ->all();
    }
}
