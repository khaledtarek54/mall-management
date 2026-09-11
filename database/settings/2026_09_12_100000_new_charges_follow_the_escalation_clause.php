<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Meeting 2026-09-02, point 24. Whether a charge added to a lease is PROPOSED as following
        // the lease's annual-increase clause. Off is Yardi's answer — a charge carries no
        // escalation until somebody states one — so nothing an install does changes on deploy.
        // The client's own rule ("the increase is on all expenses") is what they SET, per property.
        $this->migrator->add('billing.new_charges_follow_escalation', false);
    }
};
