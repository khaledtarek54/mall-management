<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Statutory withholdings used to pre-fill generated payroll lines (module 24).
        // Both ship at 0: generation then fills gross = base salary and leaves the
        // deductions for the accountant to set, so we never post a guessed Egyptian
        // rate (income tax is bracketed; social insurance rides a capped base).
        $this->migrator->add('payroll.social_insurance_rate', 0.0);
        $this->migrator->add('payroll.salary_tax_rate', 0.0);
    }
};
