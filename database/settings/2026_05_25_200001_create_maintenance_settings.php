<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Mirror current config/maintenance.php defaults
        $this->migrator->add('maintenance.sla_urgent_hours', 4);
        $this->migrator->add('maintenance.sla_high_hours', 24);
        $this->migrator->add('maintenance.sla_medium_hours', 72);
        $this->migrator->add('maintenance.sla_low_hours', 168);
    }
};
