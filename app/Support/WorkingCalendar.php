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
    /**
     * How far {@see addWorkingHours()} will walk looking for a working day before giving up.
     *
     * Only that method needs a bound: it searches forward for an open window, and a calendar with no
     * working day at all would otherwise loop. {@see workingDaysBetween()} is bounded by its own
     * range and must NOT be capped — doing so under-counted a long overrun, and that number
     * multiplies money.
     */
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
    public static function windowFor(CarbonInterface $date, ?int $assetId = null, ?array $preloaded = null): ?array
    {
        $day = CarbonImmutable::parse($date)->setTimezone(config('app.timezone'))->startOfDay();

        // `$preloaded` is the whole span's rows, fetched once by the callers that walk a range.
        // Without it `exceptionOn()` is a query PER DAY, and `daysOverSla()` runs from the hourly
        // breach scan for every overdue order — a 180-day-old breach was 180 queries an hour.
        $exception = $preloaded !== null
            ? ($preloaded[$day->toDateString()] ?? null)
            : self::exceptionOn($day, $assetId);

        if ($exception?->isClosure()) {
            return null;
        }

        $settings = app(CalendarSettings::class);

        // A short day carries its OWN hours; one with none is a data error, not a licence. The form
        // requires both, but a seeder, an import or a direct write does not — and without this an
        // hours-less short day fell through to the standard window AND skipped the weekday check,
        // turning any Friday into a full working day.
        $hasHours = $exception !== null && $exception->opens_at !== null && $exception->closes_at !== null;

        // A short day is worked even when it falls on a weekend — Ramadan hours are announced for
        // the days people are in, and an operator who enters one on a Friday means it.
        if (! $hasHours && ! in_array($day->dayOfWeekIso, self::workingDays(), true)) {
            return null;
        }

        $opens = (string) ($hasHours ? $exception->opens_at : $settings->day_opens_at);
        $closes = (string) ($hasHours ? $exception->closes_at : $settings->day_closes_at);

        [$start, $end] = [self::at($day, $opens), self::at($day, $closes)];

        if ($end->greaterThan($start)) {
            return [$start, $end];
        }

        // A closes-before-opens EXCEPTION is one bad row: that day is unworked. A closes-before-opens
        // SETTING would make every day unworked for ever, which is silent and total — so it clamps
        // back to the shipped window, the same shape `workingDays()` uses for an emptied week.
        if ($hasHours) {
            return null;
        }

        [$start, $end] = [self::at($day, '09:00'), self::at($day, '17:00')];

        return [$start, $end];
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

        // One query for the plausible span rather than one per day walked.
        $exceptions = self::exceptionsBetween($cursor, $cursor->addDays(self::MAX_WALK_DAYS), $assetId);

        for ($walked = 0; $walked <= self::MAX_WALK_DAYS; $walked++) {
            $window = self::windowFor($cursor, $assetId, $exceptions);

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
     * Elapsed WORKING time between two instants, in seconds.
     *
     * Only time inside a working window counts: nights, weekends and holidays contribute nothing.
     * This is the primitive {@see workingDaysBetween()} is built on, and the reason that method had
     * to be rewritten — counting working days *touched* is a different quantity from elapsed
     * working time, and the two are not interchangeable when one of them multiplies a penalty.
     */
    public static function workingSecondsBetween(CarbonInterface $from, CarbonInterface $to, ?int $assetId = null): int
    {
        $tz = config('app.timezone');
        $start = CarbonImmutable::parse($from)->setTimezone($tz);
        $end = CarbonImmutable::parse($to)->setTimezone($tz);

        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        $seconds = 0;
        $cursor = $start->startOfDay();
        $exceptions = self::exceptionsBetween($start, $end, $assetId);

        // Bounded by the range itself, so it always terminates and never truncates.
        while ($cursor->lessThanOrEqualTo($end)) {
            $window = self::windowFor($cursor, $assetId, $exceptions);

            if ($window !== null) {
                [$opens, $closes] = $window;

                // The slice of this day's window that lies inside [start, end].
                $sliceStart = $opens->greaterThan($start) ? $opens : $start;
                $sliceEnd = $closes->lessThan($end) ? $closes : $end;

                if ($sliceEnd->greaterThan($sliceStart)) {
                    $seconds += $sliceEnd->diffInSeconds($sliceStart, absolute: true);
                }
            }

            $cursor = $cursor->addDay();
        }

        return $seconds;
    }

    /**
     * Elapsed working time expressed in WORKING DAYS, rounding a part-day up.
     *
     * **Commensurate with the calendar measure, deliberately.** `FacilityWorkOrder::daysOverSla()`
     * charges an SLA penalty per day, and its calendar branch is `ceil(elapsedSeconds / 86400)` —
     * elapsed *duration*. The first cut of this method counted working days *touched* instead, and
     * the two are different quantities: an overrun from Sunday 17:00 to Monday 09:00 has no working
     * time in it at all, but touches two working days. That made the working clock charge an EXTRA
     * day on any overrun crossing a midnight — the ordinary Sunday-to-Thursday case — while being
     * sold as the option that charges a contractor less. Same rate, two incommensurate units.
     *
     * So: elapsed working seconds over the length of a standard working day, rounded up. A part-day
     * counts as a day, which is the documented rule; zero working time counts as zero, and the
     * CALLER decides what a breach with no working time in it should charge.
     */
    public static function workingDaysBetween(CarbonInterface $from, CarbonInterface $to, ?int $assetId = null): int
    {
        $seconds = self::workingSecondsBetween($from, $to, $assetId);

        return $seconds === 0 ? 0 : (int) ceil($seconds / self::standardDaySeconds());
    }

    /** How long a standard working day is, in seconds — the divisor above. */
    public static function standardDaySeconds(): int
    {
        $settings = app(CalendarSettings::class);
        $day = CarbonImmutable::now()->setTimezone(config('app.timezone'))->startOfDay();

        $seconds = self::at($day, $settings->day_closes_at)
            ->diffInSeconds(self::at($day, $settings->day_opens_at), absolute: true);

        // Clamp rather than divide by zero: a closes-before-opens setting is a typo, and the
        // shipped 09:00–17:00 is the honest fallback.
        return $seconds > 0 ? (int) $seconds : 8 * 3600;
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

    /**
     * Every governing row across a span, keyed by date — one query instead of one per day.
     *
     * The property's own row beats the national one, which is why the ordering matters and why the
     * later assignment must NOT overwrite an earlier property row.
     *
     * @return array<string, Holiday>
     */
    private static function exceptionsBetween(CarbonImmutable $from, CarbonImmutable $to, ?int $assetId): array
    {
        $rows = Holiday::query()
            ->active()
            // Datetime bounds, not `Y-m-d`: sqlite stores the cast value as `Y-m-d H:i:s`, so a
            // date-only upper bound excludes the very day it names. A range keeps the index usable
            // on MySQL, which a `DATE()` wrapper would not.
            ->whereBetween('date', [$from->startOfDay()->toDateTimeString(), $to->endOfDay()->toDateTimeString()])
            ->for($assetId)
            // Portfolio rows LAST, so a property row already in the map is never displaced.
            ->orderByRaw('CASE WHEN asset_id IS NULL THEN 1 ELSE 0 END')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[$row->date->toDateString()] ??= $row;
        }

        return $map;
    }

    /** The row that governs this date at this property: its own first, else the portfolio's. */
    private static function exceptionOn(CarbonImmutable $day, ?int $assetId): ?Holiday
    {
        return Holiday::query()
            ->active()
            // `whereDate`, deliberately: Eloquent's `date` cast writes `Y-m-d H:i:s` on sqlite and a
            // bare `where('date', 'Y-m-d')` therefore matches nothing there while working on MySQL —
            // the driver divergence CLAUDE.md warns about. This is the ad-hoc single-day path; the
            // loops that actually matter for index use go through `exceptionsBetween()`.
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
