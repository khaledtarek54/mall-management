<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Meeting 2026-09-02, points 1·2·3. Every default is YARDI'S, so nothing an install does
        // changes on deploy: entry executes a lease (no money gate), a reservation never lapses, and
        // a deposit is agreed as a multiple of the rent. The client's own rules — activate only once
        // the deposit or cheques are in, a 14-day hold — are what they SET, per property.
        $this->migrator->add('billing.lease_activation_requires', 'none');
        $this->migrator->add('billing.reservation_valid_days', 0);
        $this->migrator->add('billing.default_security_deposit_basis', 'months');
        $this->migrator->add('billing.default_security_deposit_percent', 0.0);
    }
};
