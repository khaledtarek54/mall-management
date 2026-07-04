<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Treasury / Custody module (module 25) — on by default, toggleable from
        // /admin/settings → Modules like every other optional module.
        $this->migrator->add('modules.custodies', true);
    }
};
