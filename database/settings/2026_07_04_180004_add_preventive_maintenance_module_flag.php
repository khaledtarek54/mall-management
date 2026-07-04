<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Preventive maintenance module (module 26) — on by default, toggleable from
        // /admin/settings → Modules like every other optional module.
        $this->migrator->add('modules.preventive_maintenance', true);
    }
};
