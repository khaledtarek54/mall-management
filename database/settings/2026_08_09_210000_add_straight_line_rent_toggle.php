<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // OFF. Straight-line recognition is standard for commercial leases (Yardi, EAS 49 /
        // IFRS 16), so the capability ships — but flipping it restates the P&L of every stepped
        // lease, which is a ruling the accountant makes, not a default anyone inherits.
        $this->migrator->add('billing.straight_line_rent_enabled', false);
    }
};
