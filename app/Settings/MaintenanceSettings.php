<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * SLA resolution hours by priority. Drives the ActionRequired widget +
 * the SLA-breached filter on maintenance requests.
 */
class MaintenanceSettings extends Settings
{
    public int $sla_urgent_hours = 4;
    public int $sla_high_hours = 24;
    public int $sla_medium_hours = 72;
    public int $sla_low_hours = 168;

    public static function group(): string
    {
        return 'maintenance';
    }
}
