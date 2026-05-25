<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('integrations.paymob_enabled', (bool) env('PAYMOB_ENABLED', false));
        $this->migrator->add('integrations.whatsapp_enabled', (bool) env('WHATSAPP_ENABLED', false));
    }
};
