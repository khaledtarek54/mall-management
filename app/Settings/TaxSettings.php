<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Egyptian tax policy the operator's accountant owns — not the codebase.
 *
 * Covers the standard VAT rate applied to taxable supplies, and withholding tax on supplier
 * payments (خصم وإضافة, Income Tax Law 91/2005 art. 59).
 * The statutory rate depends on the nature of the payment (supplies / services / contracting /
 * professional fees) and is revised by the ETA from time to time, so it is configured rather than
 * compiled in: a guessed constant would look authoritative and be wrong.
 *
 * Ships DISABLED. Switching it on changes what leaves the bank and adds a liability leg to every
 * vendor payment, so it must be a deliberate act after the accountant confirms the rates — never
 * something that silently starts happening on upgrade.
 */
class TaxSettings extends Settings
{
    /**
     * Standard VAT rate as a percentage. Egypt is at 14% (VAT Law 67/2016) and moved from 10% in
     * 2017 — read through App\Support\Vat, never as a literal. Only ORIGINATION reads this;
     * issued documents keep the rate they were billed at.
     */
    public float $vat_standard_rate = 14.0;

    public bool $wht_enabled = false;

    /** Percentage withheld from a vendor payment when the vendor has no agreed rate of its own. */
    public float $wht_default_rate = 0.0;

    public static function group(): string
    {
        return 'tax';
    }
}
