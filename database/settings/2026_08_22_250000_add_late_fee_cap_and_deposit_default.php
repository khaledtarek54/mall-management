<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // EG-35. Both ship at today's behaviour: 0 = no cap, and 3 months is the literal the
        // lease-creation service already hardcoded — so neither changes a figure on deploy.
        $this->migrator->add('billing.late_fee_maximum', 0.0);
        $this->migrator->add('billing.default_security_deposit_months', 3.0);
    }
};
