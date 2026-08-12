<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Operator-wide response targets — tier 2 of the SLA chain, beside the resolution hours.
 *
 * Deliberately a small fraction of the matching resolve target rather than a flat number: an urgent
 * job that may take 4 hours to fix cannot be allowed to sit unacknowledged for 4 hours first, and a
 * low-priority one that has 168 hours does not need somebody dropping everything within the hour.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('maintenance.sla_urgent_respond_hours', 1);
        $this->migrator->add('maintenance.sla_high_respond_hours', 4);
        $this->migrator->add('maintenance.sla_medium_respond_hours', 24);
        $this->migrator->add('maintenance.sla_low_respond_hours', 48);
    }
};
