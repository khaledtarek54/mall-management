<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * When the operator's people are at work — the week and the hours an SLA promise is measured in.
 *
 * Separate from {@see SlaSettings} on purpose: how long a job MAY take is an SLA policy, and when
 * anybody is there to do it is a fact about the business. The same calendar answers both modules,
 * and would answer a fifth consumer tomorrow without becoming an SLA setting.
 *
 * Portfolio-wide, deliberately. A mall whose FM shift genuinely works Saturdays is a real thing to
 * want and nobody has asked for it; `App\Support\WorkingCalendar` takes an `?int $assetId` so the
 * tier can be added there when somebody does, rather than shipping an override nothing consults —
 * which `PropertySettings`' own docblock names as worse than no override.
 *
 * Individual dates ARE per property: a `holidays` row carrying an `asset_id` beats the national one.
 */
class CalendarSettings extends Settings
{
    /**
     * Sunday–Thursday, as ISO weekday numbers (1 = Monday … 7 = Sunday).
     *
     * Egypt's weekend is Friday and Saturday. Stated as a constant as well as a default because
     * `WorkingCalendar::workingDays()` clamps back to it when the setting is emptied — an empty
     * working week would mean no work is ever done and every deadline would walk off the end.
     */
    public const EGYPTIAN_WEEK = [7, 1, 2, 3, 4];

    /** @var array<int, int> */
    public array $working_days = self::EGYPTIAN_WEEK;

    /** Start of the working day, `HH:MM` in the application timezone. */
    public string $day_opens_at = '09:00';

    /**
     * End of the working day, `HH:MM`.
     *
     * Ramadan's six-hour day is NOT set here — it is a `short_day` row in `holidays`, because it
     * applies to specific dates that move every year and cannot be a standing setting.
     */
    public string $day_closes_at = '17:00';

    public static function group(): string
    {
        return 'calendar';
    }
}
