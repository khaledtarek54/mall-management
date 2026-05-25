<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * SLA resolution hours by priority. Drives the ActionRequired widget +
 * the SLA-breached filter on maintenance requests.
 */
class MaintenanceSettings extends Settings
{
    public int $sla_urgent_hours;
    public int $sla_high_hours;
    public int $sla_medium_hours;
    public int $sla_low_hours;

    public static function group(): string
    {
        return 'maintenance';
    }
}
