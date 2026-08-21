<?php

namespace App\Support;

use App\Models\Holiday;
use App\Settings\CalendarSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * When the mall's people are actually at work — the clock an SLA promise is kept against.
 *
 * Egypt's working week is **Sunday to Thursday**; the weekend is Friday and Saturday. Until this
 * existed every SLA deadline in the system was `now()->addHours($n)` on a bare calendar, so a
 * 24-hour urgent job raised Thursday 17:00 fell due Friday 17:00 with nobody on site — and vendor
 * SLA penalties, which post to the general ledger, were computed off that. This is not a display
 * concern.
 *
 * ## Three inputs, in this order
 *
 *   1. **A `holidays` row for the property**, then a portfolio-wide one for the same date. The
 *      property's own row wins, which is how one mall trades through a national holiday.
 *   2. **{@see CalendarSettings}** — which weekdays are worked and between which hours.
 *   3. Nothing else. There is no per-property working week: a mall whose FM shift genuinely differs
 *      is a real thing to want and nobody has asked for it, so it is not built. Adding it later is
 *      a tier on this class, not a second class.
 *
 * ## Timezone is explicit, and that is load-bearing
 *
 * Production runs `Africa/Cairo` (`config/app.php`) and the test suite pins `UTC`
 * (`phpunit.xml`) so its determinism is a stated choice. A job raised Friday 00:30 in Cairo is
 * Thursday 22:30 in UTC — a working day in one and the weekend in the other, which is exactly the
 * boundary this class exists to get right. So every day-and-window decision converts to the
 * APPLICATION timezone first and never asks the incoming instant what day it thinks it is.
 *
 * ## What it deliberately does not answer
 *
 * Nothing about **money dates**. Invoice due dates, AR ageing, late-fee grace and lease terms are
 * calendar time by law and by contract, in Egypt as in Yardi, and are not this class's business.
 * Nothing about **PM compliance** either: skipping Fri/Sat before calling a preventive round late
 * would be a tolerance window, which module 26 refuses by design.
 */
final class WorkingCalendar
{
    /** A deadline cannot be pushed past this many days of walking; a mistyped calendar must not hang the request. */
    private const MAX_WALK_DAYS = 400;

    /** Is any work done on this date at this property? */
    public static function isWorkingDay(CarbonInterface $date, ?int $assetId = null): bool
    {
        return self::windowFor($date, $assetId) !== null;
    }

    /**
     * The hours worked on this date, or null when none are.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null [opens, closes] in app time
     */
    public static function windowFor(CarbonInterface $date, ?int $assetId = null): ?array
    {
        $day = CarbonImmutable::parse($date)->setTimezone(config('app.timezone'))->startOfDay();
        $exception = self::exceptionOn($day, $assetId);

        if ($exception?->isClosure()) {
            return null;
        }

        $settings = app(CalendarSettings::class);

        // A short day is worked even when it falls on a weekend — Ramadan hours are announced for
        // the days people are in, and an operator who enters one on a Friday means it.
        if ($exception === null && ! in_array($day->dayOfWeekIso, self::workingDays(), true)) {
            return null;
        }

        $opens = (string) ($exception?->opens_at ?: $settings->day_opens_at);
        $closes = (string) ($exception?->closes_at ?: $settings->day_closes_at);

        [$start, $end] = [self::at($day, $opens), self::at($day, $closes)];

        // A window that closes before it opens is a typo, not a rule. Treat the day as unworked
        // rather than looping forever or billing a negative one.
        return $end->greaterThan($start) ? [$start, $end] : null;
    }

    /**
     * `$from` plus `$hours` of WORKING time.
     *
     * Time outside a working window does not count: an instant before the day opens is pulled
     * forward to the opening, one after it closes rolls to the next working day, and a weekend or a
     * holiday is skipped whole. The result is always inside a working window.
     */
    public static function addWorkingHours(CarbonInterface $from, float $hours, ?int $assetId = null): CarbonImmutable
    {
        $cursor = CarbonImmutable::parse($from)->setTimezone(config('app.timezone'));
        $remaining = max(0.0, $hours) * 3600;

        for ($walked = 0; $walked <= self::MAX_WALK_DAYS; $walked++) {
            $window = self::windowFor($cursor, $assetId);

            if ($window === null) {
                $cursor = $cursor->addDay()->startOfDay();

                continue;
            }

            [$opens, $closes] = $window;

            // Before the day starts, the clock starts when the day does.
            if ($cursor->lessThan($opens)) {
                $cursor = $opens;
            }

            if ($cursor->greaterThanOrEqualTo($closes)) {
                $cursor = $cursor->addDay()->startOfDay();

                continue;
            }

            $available = $closes->diffInSeconds($cursor, absolute: true);

            if ($remaining <= $available) {
                return $cursor->addSeconds((int) round($remaining));
            }

            $remaining -= $available;
            $cursor = $cursor->addDay()->startOfDay();
        }

        // A calendar with no working days at all would otherwise loop. Fall back to calendar time,
        // which is the behaviour that existed before this class — wrong, but not a hung request.
        return CarbonImmutable::parse($from)->addSeconds((int) round(max(0.0, $hours) * 3600));
    }

    /**
     * Whole working days from `$from` to `$to`, counting a part-day as a day.
     *
     * Used for the SLA-penalty overrun, where "part of a day counts as a day" is the documented
     * rule and a zero would read as "assessed and owed nothing". Returns 0 only when `$to` is not
     * after `$from`; the caller decides what a breach with no working time in it should charge.
     */
    public static function workingDaysBetween(CarbonInterface $from, CarbonInterface $to, ?int $assetId = null): int
    {
        $start = CarbonImmutable::parse($from)->setTimezone(config('app.timezone'));
        $end = CarbonImmutable::parse($to)->setTimezone(config('app.timezone'));

        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        $days = 0;
        $cursor = $start->startOfDay();

        for ($walked = 0; $walked <= self::MAX_WALK_DAYS && $cursor->lessThanOrEqualTo($end); $walked++) {
            if (self::isWorkingDay($cursor, $assetId)) {
                $days++;
            }

            $cursor = $cursor->addDay();
        }

        return $days;
    }

    /**
     * The working weekdays, as ISO numbers (1 = Monday … 7 = Sunday).
     *
     * Coerced to int on the way out: a Filament CheckboxList round-trips through settings as
     * strings, and `in_array($iso, $week, true)` against `['7','1']` matches nothing — which would
     * make every day non-working and every deadline walk to the fallback. The same coercion
     * `AgingBuckets` applies to its configured boundaries, for the same reason.
     *
     * @return array<int, int>
     */
    public static function workingDays(): array
    {
        $configured = collect(app(CalendarSettings::class)->working_days)
            ->map(fn ($day): int => (int) $day)
            ->filter(fn (int $day): bool => $day >= 1 && $day <= 7)
            ->unique()
            ->values()
            ->all();

        // Clamp rather than throw, the house pattern for a mistyped setting: an empty week would
        // mean no work is ever done and every SLA deadline would walk to the fallback.
        return $configured !== [] ? $configured : CalendarSettings::EGYPTIAN_WEEK;
    }

    /** The row that governs this date at this property: its own first, else the portfolio's. */
    private static function exceptionOn(CarbonImmutable $day, ?int $assetId): ?Holiday
    {
        return Holiday::query()
            ->active()
            ->whereDate('date', $day->toDateString())
            ->for($assetId)
            // A property's own row beats the national one. `orderByRaw` rather than a sort on the
            // collection so the decision is made by the database and not by row order.
            ->orderByRaw('CASE WHEN asset_id IS NULL THEN 1 ELSE 0 END')
            ->first();
    }

    private static function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return $day->setTime((int) $hour, (int) $minute);
    }
}
