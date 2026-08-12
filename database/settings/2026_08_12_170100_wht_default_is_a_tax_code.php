<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * The withholding default becomes a tax CODE rather than a percentage.
 *
 * `wht_default_rate` was the last rate left in settings. It carried the flaw `TaxSettings` itself
 * named — the statutory figure depends on the nature of the payment, so a single portfolio number
 * is a guess that looks official — and it could not be dated.
 *
 * `wht_default_tax_code` is policy, which is what settings are for: *which nature do we assume when
 * a supplier's own has not been ruled on*. The RATE that code carries, and the day it took effect,
 * live in the catalogue with every other rate.
 *
 * Ships EMPTY. There is no defensible default nature — supplies, services, contracting and
 * professional fees all differ — and withholding stays disabled until the accountant sets both this
 * and `wht_enabled`. An empty default withholds nothing, which is the safe direction.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('tax.wht_default_tax_code', '');
        $this->migrator->delete('tax.wht_default_rate');
    }
};
