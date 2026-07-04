<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Fixed Assets & Depreciation module (module 23) — on by default, toggleable
        // from /admin/settings → Modules like every other optional module.
        $this->migrator->add('modules.fixed_assets', true);
    }
};
