<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // EG-03. Settings hold POLICY, master data holds RATES — the same split that removed
        // `TaxSettings::vat_standard_rate` on 2026-08-12 when the tax catalogue landed.
        //
        // These three were undated scalars, so a January run generated in March used March's
        // numbers and nothing recorded what a past run was computed with. They now live on
        // `payroll_rates` as dated rungs, and `2026_08_22_740000_payroll_rates_become_dated_rungs`
        // has already carried each operator's current value across — so this deletes a copy, not a
        // number. Removing them is the point: two homes for one figure is how the two come to
        // disagree, and only one of them can be dated.
        //
        // `gratuity_*` stays in settings. Whether this workforce is entitled to a gratuity is a
        // policy question about their contracts, not a rate the state publishes each January.
        $this->migrator->delete('payroll.social_insurance_rate');
        $this->migrator->delete('payroll.employer_social_insurance_rate');
        $this->migrator->delete('payroll.salary_tax_rate');
    }
};
