<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * The AR ageing boundaries become the operator's, not a constant.
 *
 * Seeded with 30/60/90 — exactly what the code hard-coded — so no existing report moves on upgrade.
 * What changes is that moving them is now a setting rather than a deploy.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('billing.ar_aging_bucket_days', [30, 60, 90]);
    }
};
