<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Meeting 2026-09-02, point 14. How the acquisition month is charged: whole, or by the
        // days the asset was held. `full_month` is what every install did before the setting
        // existed, so nothing an install already depreciates moves on deploy; `days` is the
        // accountant's ask and is what they SET.
        $this->migrator->add('accounting.depreciation_proration', 'full_month');
    }
};
