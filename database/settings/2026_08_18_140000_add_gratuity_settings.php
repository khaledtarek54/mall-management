<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * End-of-service gratuity (مكافأة نهاية الخدمة) — the accrual, switched OFF.
 *
 * Egyptian Labour Law 12/2003 Art. 122 sets the formula (half a month per year for the first five
 * years, a month per year thereafter), but it applies to workers NOT covered by the social
 * insurance law — and in Egypt most employees are. So entitlement is a question about this
 * workforce's contracts and insurance status, not something the software can assume. Accruing a
 * provision nobody owes overstates the liability exactly as surely as omitting a real one
 * understates it, which is why the flag ships false and the accountant turns it on.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('payroll.gratuity_enabled', false);
        $this->migrator->add('payroll.gratuity_days_first_five', 15.0);
        $this->migrator->add('payroll.gratuity_days_thereafter', 30.0);
    }

    public function down(): void
    {
        $this->migrator->delete('payroll.gratuity_enabled');
        $this->migrator->delete('payroll.gratuity_days_first_five');
        $this->migrator->delete('payroll.gratuity_days_thereafter');
    }
};
