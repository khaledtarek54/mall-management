<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Egyptian tax policy the operator's accountant owns — not the codebase.
 *
 * Currently withholding tax on supplier payments (خصم وإضافة, Income Tax Law 91/2005 art. 59).
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
    public bool $wht_enabled = false;

    /** Percentage withheld from a vendor payment when the vendor has no agreed rate of its own. */
    public float $wht_default_rate = 0.0;

    public static function group(): string
    {
        return 'tax';
    }
}
