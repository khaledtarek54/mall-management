<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Marketing Fund / Promotional Levy — 5% of base rent (FR MKT-2),
        // market-validated and operator-tunable later.
        $this->migrator->add('marketing.levy_rate_percent', 5.0);
    }
};
