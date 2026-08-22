<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // EG-35. 0 = charge once per invoice, which is what every install has done since late fees
        // existed — so no penalty changes on deploy.
        $this->migrator->add('billing.late_fee_recurrence_days', 0);
    }
};
