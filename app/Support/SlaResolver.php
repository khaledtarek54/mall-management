<?php

namespace App\Support;

use App\Models\SlaPolicy;
use App\Settings\SlaSettings;

/**
 * How many hours a job of a given priority has, at a given property (FR-CM-05/06).
 *
 * TWO clocks, resolved through the identical three-tier chain. **Resolution** is how long the job
 * may take once accepted; **response** is how long it may sit before anybody accepts it. The second
 * exists because FR-CM-07 deliberately starts the resolution clock at acceptance — so an engineer
 * is not charged for queue time — which left the queue accountable to nobody, and an order nobody
 * accepted with no deadline at all.
 *
 * Resolution order, most specific first:
 *   1. an ACTIVE `sla_policies` row for that property + priority — the FR-CM-05 per-mall override
 *   2. `SlaSettings` — the operator-wide singleton, tunable in /admin/settings
 *   3. `config/sla.php` — the shipped default, for a fresh install with no settings row
 *
 * ⚠️ **Tiers 2 and 3 disagree in this repo**, and have since before this class existed:
 * settings say urgent=4h / medium=72h, config says urgent=24h / medium=168h. That was
 * harmless only because nothing read config in practice. Making the chain explicit here
 * means the disagreement now has one documented winner — settings — with config as a true
 * last resort. Don't "fix" config to match: it is the cold-start default, and changing it
 * would silently re-time every existing install that has no settings row.
 */
class SlaResolver
{
    /** SLA measured on bare calendar hours — the behaviour that predates the working calendar. */
    public const CLOCK_CALENDAR = 'calendar';

    /** SLA measured in working time: the working week, working hours, holidays skipped. */
    public const CLOCK_WORKING = 'working';

    /** @var array<int, string> */
    public const CLOCKS = [self::CLOCK_CALENDAR, self::CLOCK_WORKING];

    /**
     * @param  int|null  $assetId  the property the job belongs to; null skips the per-mall tier
     */
    public static function hoursFor(?int $assetId, string $priority): int
    {
        if ($assetId !== null) {
            // active() only: deactivating an override is how a manager returns a property
            // to the default, since deleting the row is super_admin-only project-wide.
            //
            // Type-blind on purpose. A `sla_policies` row may NAME a request type (module 11 added
            // that dimension), and the per-type tier is resolved by `TenantRequestService`, which is
            // the only caller that has a type — a work order does not. Reading a typed row here
            // would apply a "complaints in 8 hours" rule to facility jobs.
            $override = SlaPolicy::query()
                ->active()
                ->where('request_type', SlaPolicy::ANY_TYPE)
                ->where('asset_id', $assetId)
                ->where('priority', $priority)
                ->value('resolve_hours');

            if ($override !== null) {
                return (int) $override;
            }
        }

        return self::globalHoursFor($priority);
    }

    /**
     * How long a job of this priority may sit before somebody accepts it.
     *
     * Same three tiers as `hoursFor()`, deliberately: one chain, so a property that overrides its
     * SLA overrides both halves of it in the same place and in the same way. `respond_hours` is
     * nullable on the policy row — a property may override only the resolution target and take the
     * operator-wide response target, which is a real thing to want.
     *
     * @param  int|null  $assetId  the property the job belongs to; null skips the per-mall tier
     */
    public static function respondHoursFor(?int $assetId, string $priority): int
    {
        if ($assetId !== null) {
            $override = SlaPolicy::query()
                ->active()
                ->where('asset_id', $assetId)
                ->where('priority', $priority)
                ->value('respond_hours');

            if ($override !== null) {
                return (int) $override;
            }
        }

        return self::globalRespondHoursFor($priority);
    }

    /**
     * Is a job of this priority measured on the CALENDAR or in WORKING time?
     *
     * Here rather than on {@see WorkingCalendar} deliberately: that class is pure
     * date arithmetic, and this is the same resolution question `hoursFor()` answers — a second
     * three-tier chain beside this one would be two ways to say the same thing, which is how they
     * come to disagree.
     *
     * One tier today, not three. The split the operator will make is by PRIORITY (an urgent chiller
     * failure is a 24-hour promise whatever day it is; a signage approval is office work), not by
     * property — and `PropertySettings`' own docblock warns that an override nothing consults is
     * worse than none. The `?int $assetId` is here so a per-property tier can be added ABOVE this
     * one, in this method, when a mall actually differs.
     *
     * Answers for BOTH modules — work orders and tenant requests share `SlaSettings`, so a knob
     * honoured by one of them and ignored by the other is the split-brain the maintenance rename
     * was done to end.
     *
     * @param  int|null  $assetId  reserved for the per-property tier; unused today, and said so
     */
    public static function clockFor(?int $assetId, string $priority): string
    {
        try {
            $working = collect(app(SlaSettings::class)->sla_working_clock_priorities)
                ->map(fn ($value): string => (string) $value)
                ->all();
        } catch (\Throwable) {
            // The settings store may not be readable at all — a fresh box before `atriom:install`,
            // a settings migration not yet run, or the `reset.sh` path that restores a dump WITHOUT
            // migrating. `TenantRequestService::defaultTargetResolution()` has carried this guard
            // since audit M09 F-36 so that such a box still produces a sensible deadline from
            // `config/sla.php`; routing an UNGUARDED read in front of it turned tenant-request
            // creation — portal, API and admin — into a 500 on exactly those boxes, with the
            // feature switched off. The calendar clock is the right answer here: it is what
            // predates the setting, so an unreadable store behaves as an empty one.
            return self::CLOCK_CALENDAR;
        }

        return in_array($priority, $working, true)
            ? self::CLOCK_WORKING
            : self::CLOCK_CALENDAR;
    }

    /** The operator-wide response default for a priority — tier 2, falling back to tier 3. */
    public static function globalRespondHoursFor(string $priority): int
    {
        $settings = app(SlaSettings::class);

        $fromSettings = match ($priority) {
            'urgent' => $settings->sla_urgent_respond_hours,
            'high' => $settings->sla_high_respond_hours,
            'medium' => $settings->sla_medium_respond_hours,
            'low' => $settings->sla_low_respond_hours,
            default => null,
        };

        if ($fromSettings !== null && $fromSettings > 0) {
            return (int) $fromSettings;
        }

        return (int) config("sla.{$priority}.respond_hours", 24);
    }

    /** The operator-wide default for a priority — tier 2, falling back to tier 3. */
    public static function globalHoursFor(string $priority): int
    {
        $settings = app(SlaSettings::class);

        $fromSettings = match ($priority) {
            'urgent' => $settings->sla_urgent_hours,
            'high' => $settings->sla_high_hours,
            'medium' => $settings->sla_medium_hours,
            'low' => $settings->sla_low_hours,
            default => null,
        };

        if ($fromSettings !== null && $fromSettings > 0) {
            return (int) $fromSettings;
        }

        // Tier 3. An unknown priority can't reach here through the app (both the model and
        // the form constrain it), so the final default is a backstop, not a real path.
        return (int) config("sla.{$priority}.resolve_hours", 72);
    }
}
