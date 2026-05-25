<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('eta.enabled', (bool) env('ETA_ENABLED', true));
        $this->migrator->add('eta.mock', (bool) env('ETA_MOCK', true));
        $this->migrator->add('eta.issuer_name', env('ETA_ISSUER_NAME', 'Atriom Demo Operator'));
        $this->migrator->add('eta.issuer_tax_registration_number', env('ETA_ISSUER_TRN', '123-456-789'));
    }
};
