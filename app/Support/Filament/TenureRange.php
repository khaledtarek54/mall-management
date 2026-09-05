<?php

namespace App\Support\Filament;

use Carbon\CarbonImmutable;
use Closure;
use Filament\Schemas\Components\Utilities\Get;
use Throwable;

/**
 * The end of a tenure may equal its start — a one-day assignment is an ordinary thing.
 *
 * Reported by the tester on a property's Assigned Staff: Assigned 30 Sep, Ended 30 Sep, refused with
 * *"The ended field must be a date after or equal to assigned."* — a message stating the very rule
 * it was breaking.
 *
 * **Stated plainly: this could not be reproduced on SQLite at HEAD.** Driving the real relation
 * manager and dumping Filament's generated rule shows `after_or_equal:` resolved correctly to the
 * sibling state path, and equal dates pass. What this codebase has already learned once, and
 * recorded, is the shape that explains it anyway:
 *
 *   > `coversWholeMonth()` compares DAY boundaries, never timestamps — a period end through
 *   > `startOfDay()` reads 00:00:00 against `endOfMonth()`'s 23:59:59, so the last day counted as
 *   > uncovered and **the bug was intermittent by caller**.
 *
 * `after_or_equal` compares INSTANTS. Give either side a time component — from a `->default(now())`
 * that was never floored, a driver returning `2026-09-30 00:00:00` where another returns
 * `2026-09-30`, a column that is `datetime` on one box and `date` on another — and two dates that
 * are the same DAY are no longer the same instant, so "equal" fails while "after" still works.
 * That is exactly the asymmetry the tester saw, and exactly why it does not reproduce here.
 *
 * So the comparison is pinned to midnight rather than left to whatever the two sides happen to
 * carry. `minDate()` is used instead of `afterOrEqual()` deliberately, and it buys two things:
 * Filament resolves it to a VALUE (so the rule becomes `after_or_equal:2026-09-30`, with no state
 * path to resolve and no second side to carry a stray time), and the calendar DISABLES the days
 * before it — so an impossible date cannot be picked in the first place, which is the better half.
 * A range picker that greys out what it will refuse is the market-standard control here.
 */
class TenureRange
{
    /**
     * The floor for an END date: the START date, at midnight.
     *
     * Null while the start is empty — an end date with no start is a different question, and one
     * this rule has no opinion about.
     */
    public static function endsOnOrAfter(string $startPath): Closure
    {
        return static function (Get $get) use ($startPath): ?CarbonImmutable {
            $start = $get($startPath);

            if (blank($start)) {
                return null;
            }

            try {
                return CarbonImmutable::parse($start)->startOfDay();
            } catch (Throwable) {
                // A half-typed date is the other field's problem to refuse, not this one's — and
                // throwing here would take the whole form down while somebody is still typing.
                return null;
            }
        };
    }
}
