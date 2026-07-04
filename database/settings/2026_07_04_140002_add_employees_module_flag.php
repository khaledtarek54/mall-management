<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // HR / Employees module (module 24) — on by default, toggleable from
        // /admin/settings → Modules like every other optional module.
        $this->migrator->add('modules.employees', true);
    }
};
