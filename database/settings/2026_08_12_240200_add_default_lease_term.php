<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // 36 is what the lease form hardcoded, so an existing install is unchanged.
        $this->migrator->add('accounting.default_lease_term_months', 36);
    }

    public function down(): void
    {
        $this->migrator->delete('accounting.default_lease_term_months');
    }
};
