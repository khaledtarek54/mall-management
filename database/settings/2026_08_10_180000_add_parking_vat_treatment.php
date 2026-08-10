<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Is parking a VATable supply in Egypt? (space model, module 35)
 *
 * Rent is exempt, service charge is standard-rated, and **parking is neither obviously** — it is a
 * licence to use a space rather than a lease of it, which is exactly the kind of distinction the VAT
 * Law 67/2016 schedules settle and a developer does not.
 *
 * So it is configured, not compiled in — the same reasoning as `vat_standard_rate` itself: a guessed
 * constant would look authoritative and be wrong. Ships EXEMPT, which is what the code already did
 * and the conservative direction: it under-charges the tenant rather than collecting tax that may
 * not be due and having to refund it.
 *
 * The accountant flips one switch when they rule. Only ORIGINATION reads it, so a change never
 * rewrites an issued invoice.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('tax.parking_vat_applicable', false);
    }
};
