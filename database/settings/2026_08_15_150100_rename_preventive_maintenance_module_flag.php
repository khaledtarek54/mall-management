<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * The facility module's toggle stops calling itself "preventive maintenance".
 *
 * It never gated only preventive work: the same flag hides corrective work orders, service plans
 * and the equipment register — the whole Facility nav group. `modules.facility` says what it does,
 * and matches the `facility.*` permissions renamed alongside it in 2026_08_15_150000.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        if ($this->migrator->exists('modules.preventive_maintenance')) {
            $this->migrator->rename('modules.preventive_maintenance', 'modules.facility');
        }
    }

    public function down(): void
    {
        if ($this->migrator->exists('modules.facility')) {
            $this->migrator->rename('modules.facility', 'modules.preventive_maintenance');
        }
    }
};
