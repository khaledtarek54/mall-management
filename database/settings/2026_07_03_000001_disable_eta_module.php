<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // ETA e-invoicing is postponed (not certified/live). Turn the module off
        // for existing installs — hides the Submit-to-ETA actions + the EtaCompliance
        // widget. Reversible from /admin/settings → Modules when ETA is ready.
        $this->migrator->update('modules.eta', fn () => false);
    }

    public function down(): void
    {
        $this->migrator->update('modules.eta', fn () => true);
    }
};
