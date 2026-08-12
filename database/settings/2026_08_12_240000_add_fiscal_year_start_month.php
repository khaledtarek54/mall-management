<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // 1 = January, which is what FiscalCalendar hardcoded — so an existing install is unchanged
        // by this migration and only moves when somebody deliberately sets it.
        $this->migrator->add('accounting.fiscal_year_start_month', 1);
    }

    public function down(): void
    {
        $this->migrator->delete('accounting.fiscal_year_start_month');
    }
};
