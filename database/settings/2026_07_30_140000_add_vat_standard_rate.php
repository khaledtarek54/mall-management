<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // The standard Egyptian VAT rate, previously a constant repeated across eight code sites
        // (utility recharge, new-lease service charge, CAM admin fee ×3, the invoice-line form ×2,
        // and a bare `* 0.14`). Egypt moved this rate once already (10% → 14% in 2017); when it
        // moves again the operator's accountant changes it here, not a developer in five files.
        //
        // Seeded at 14.0 = today's statutory rate, so nothing about existing behaviour changes.
        $this->migrator->add('tax.vat_standard_rate', 14.0);
    }
};
