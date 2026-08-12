<?php

namespace App\Support;

use App\Models\SlaPolicy;
use App\Settings\MaintenanceSettings;

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
 *   2. `MaintenanceSettings` — the operator-wide singleton, tunable in /admin/settings
 *   3. `config/maintenance.php` — the shipped default, for a fresh install with no settings row
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
    /**
     * @param  int|null  $assetId  the property the job belongs to; null skips the per-mall tier
     */
    public static function hoursFor(?int $assetId, string $priority): int
    {
        if ($assetId !== null) {
            // active() only: deactivating an override is how a manager returns a property
            // to the default, since deleting the row is super_admin-only project-wide.
            $override = SlaPolicy::query()
                ->active()
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

    /** The operator-wide response default for a priority — tier 2, falling back to tier 3. */
    public static function globalRespondHoursFor(string $priority): int
    {
        $settings = app(MaintenanceSettings::class);

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

        return (int) config("maintenance.sla.{$priority}.respond_hours", 24);
    }

    /** The operator-wide default for a priority — tier 2, falling back to tier 3. */
    public static function globalHoursFor(string $priority): int
    {
        $settings = app(MaintenanceSettings::class);

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
        return (int) config("maintenance.sla.{$priority}.resolve_hours", 72);
    }
}
