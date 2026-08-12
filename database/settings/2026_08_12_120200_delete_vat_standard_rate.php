<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * The standard VAT rate moves from a settings field to a dated rung on `VAT_STD`.
 *
 * `tax.vat_standard_rate` was a real improvement on the eight hard-coded `14`s it replaced, and
 * still the wrong shape: a rate has a date, and a settings field cannot carry one. Editing it
 * re-rated everything originated afterwards — including a document back-dated into the previous
 * regime — and it could not express "14% until 31 December, 15% from 1 January", which is the form
 * a rate change actually arrives in.
 *
 * The value is not lost: the schema migration that runs immediately before this one reads it and
 * writes it as the opening rung of the `VAT_STD` ladder, so a mall that had moved the rate off 14
 * keeps billing its own rate.
 *
 * `wht_default_rate` deliberately stayed at this point — it was still the live mechanism for
 * withholding until the vendor-payment path was wired to the `WH_*` codes. It was replaced in the
 * change that replaced it (TX-05, the same day), not left half-dead here.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->delete('tax.vat_standard_rate');
    }
};
