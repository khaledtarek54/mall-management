<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // The default multiple of last rent charged when a lease holds over, as a percentage.
        // 150% is the standard Egyptian commercial default and is a deterrent by design — holdover
        // is meant to be more expensive than renewing. Per-lease terms override it; this is only
        // what the conversion form proposes when the lease says nothing.
        $this->migrator->add('billing.holdover_default_rate_pct', 150.0);
    }
};
