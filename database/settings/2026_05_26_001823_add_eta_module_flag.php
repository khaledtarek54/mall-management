<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // ETA e-invoicing is opt-in per deployment. Default ON so existing
        // installs keep their current behaviour (the EtaCompliance widget
        // and ETA submission actions were always visible before this flag
        // existed).
        $this->migrator->add('modules.eta', true);
    }
};
