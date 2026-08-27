<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // D2-09. EG-34 gave the audit trail a retention period and stopped there; these are the five
        // other tables that grow with nothing to prune them. Defaults are deliberately unremarkable
        // — the point is that a period EXISTS and is on a screen, not that it is aggressive.
        $this->migrator->add('housekeeping.notification_retention_days', 90);
        $this->migrator->add('housekeeping.export_retention_days', 30);
        $this->migrator->add('housekeeping.import_retention_days', 30);
        $this->migrator->add('housekeeping.failed_job_retention_days', 30);
        $this->migrator->add('housekeeping.expired_token_grace_days', 7);
    }
};
