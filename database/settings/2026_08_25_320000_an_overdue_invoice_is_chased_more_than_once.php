<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // 1A-16, the dunning ladder. 0 = chase once per invoice, which is exactly what every install
        // has done since the tenant reminder existed — so no tenant receives a message they would
        // not have received yesterday. The ceiling is inert until the cadence is set.
        $this->migrator->add('billing.dunning_followup_days', 0);
        $this->migrator->add('billing.dunning_max_notices', 3);
    }
};
