<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Withholding tax on vendor payments (module 12b). OFF by default: enabling it changes
        // what leaves the bank and books a liability to the ETA, so it must be a deliberate act
        // once the operator's accountant has confirmed the rates for their payment categories.
        $this->migrator->add('tax.wht_enabled', false);
        $this->migrator->add('tax.wht_default_rate', 0.0);
    }
};
