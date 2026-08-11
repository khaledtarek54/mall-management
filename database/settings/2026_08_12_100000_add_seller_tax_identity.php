<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * The seller identity a tax invoice is legally required to carry.
 *
 * Atriom's invoice PDF is titled "Tax Invoice" and printed the property's name, address and city —
 * with no tax registration number anywhere on it. Under the VAT Law 67/2016 executive regulations
 * the supplier's registration number is a required particular, and **without it the tenant cannot
 * support an input-VAT deduction** from the document they were given.
 *
 * Both default to EMPTY rather than to a plausible-looking placeholder. A fake TRN on a tax invoice
 * is worse than a missing one: it looks valid, the tenant files it, and it fails on audit — so the
 * PDF prints the line only when the value is set. Filling this is a go-live gate item.
 *
 * Not read from `ETA_ISSUER_TRN`: that env var seeds the e-invoicing module's own copy, whose
 * shipped default is the placeholder `123-456-789`, and copying it here would put exactly that
 * string on a real invoice.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('tax.seller_tax_registration_number', '');
        $this->migrator->add('tax.seller_legal_name', '');
    }
};
