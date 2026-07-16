<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Procurement module (module 29, FR-PROC-*) — on by default, toggleable from
        // /admin/settings → Modules like every other optional module.
        $this->migrator->add('modules.procurement', true);
    }
};
