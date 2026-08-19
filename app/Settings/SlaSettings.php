<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * SLA hours by priority — shared by tenant requests AND facility work orders — TWO clocks. Drives the ActionRequired widget + the SLA-breached filters.
 *
 * **Resolution** is how long the job may take once somebody has taken it on (FR-CM-07: the clock
 * starts at acceptance, so an engineer is not charged for queue time). **Response** is how long it
 * may sit before anybody does — the other side of that trade, because if queue time is not the
 * engineer's problem it has to be somebody's. Without it, never accepting a job meant it had no
 * deadline at all.
 */
class SlaSettings extends Settings
{
    public int $sla_urgent_hours = 4;

    public int $sla_high_hours = 24;

    public int $sla_medium_hours = 72;

    public int $sla_low_hours = 168;

    public int $sla_urgent_respond_hours = 1;

    public int $sla_high_respond_hours = 4;

    public int $sla_medium_respond_hours = 24;

    public int $sla_low_respond_hours = 48;

    /**
     * Refuse to mark a work order done until it carries at least one attachment.
     *
     * Ships FALSE. Switching it on mid-flight would refuse the next completion every engineer
     * attempts, on jobs they have already finished — and the reliable outcome of that is a
     * photograph of a wall, taken to clear the validation. Evidence collected to satisfy a gate is
     * worse than none, because it looks like proof. Attachments first, habit second, requirement
     * third.
     */
    public bool $require_completion_evidence = false;

    public static function group(): string
    {
        return 'sla';
    }
}
