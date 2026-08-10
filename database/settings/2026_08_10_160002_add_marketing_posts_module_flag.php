<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Marketing posts / shopper feed (module 36) — on by default, toggleable from
        // /admin/settings → Modules like every other optional module. Turning it off also
        // 404s the public visitor API, not just the sidebar entry.
        $this->migrator->add('modules.marketing_posts', true);
    }
};
