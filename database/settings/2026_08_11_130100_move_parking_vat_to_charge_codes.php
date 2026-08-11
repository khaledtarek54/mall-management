<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Parking's taxability moves from a settings toggle to its charge-code row.
 *
 * `tax.parking_vat_applicable` was the right decision in the wrong place: it answered "is this
 * supply taxable?" for exactly one charge code, on a screen nowhere near the catalogue where that
 * question is now answered for every other code. Two homes for one answer is the bug class this
 * project keeps paying for — an operator could set the toggle on and the `parking` code to exempt,
 * and only `AssignRentableItemService` knew which one won.
 *
 * The value is not lost: the schema migration that runs immediately before this one reads it and
 * writes it onto `charge_codes.vat_treatment`, so a mall that had already ruled parking taxable
 * keeps billing it taxable.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->delete('tax.parking_vat_applicable');
    }
};
