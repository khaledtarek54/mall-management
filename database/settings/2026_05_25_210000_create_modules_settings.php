<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Every optional module defaults to enabled. Operators turn them off
        // from /admin/settings → Modules if they don't want them in the demo
        // or in production for a specific deployment.
        $this->migrator->add('modules.credit_notes',    true);
        $this->migrator->add('modules.maintenance',     true);
        $this->migrator->add('modules.tenant_sales',    true);
        $this->migrator->add('modules.cam',             true);
        $this->migrator->add('modules.utility_meters',  true);
        $this->migrator->add('modules.vendors',         true);
        $this->migrator->add('modules.notes',           true);
        $this->migrator->add('modules.reports',         true);
        $this->migrator->add('modules.activity_log',    true);
    }
};
