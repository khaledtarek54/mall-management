<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Which SLA priorities are measured in working time. Empty = every clock stays on the calendar,
 * which is the behaviour that existed before the working calendar and changes nothing on upgrade.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('sla.sla_working_clock_priorities', []);
    }

    public function down(): void
    {
        $this->migrator->delete('sla.sla_working_clock_priorities');
    }
};
