<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Employer social-insurance contribution rate (module 24, Phase 4a). A separate
        // migration from the initial payroll settings because that one already ran on
        // deployed databases — adding the key here lets it apply cleanly. 0 by default:
        // the employer share is a company cost the accountant confirms before it posts.
        $this->migrator->add('payroll.employer_social_insurance_rate', 0.0);
    }
};
