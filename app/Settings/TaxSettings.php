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

    /*
     * WHICH supplies that rate applies to is NOT here. It is `charge_codes.vat_treatment` —
     * standard / exempt / zero-rated per charge code, with an optional per-code rate override —
     * read through `App\Support\Vat::rateForType()`. Parking had a toggle of its own on this class
     * until 2026-08-11; it was the same question answered in a second place, and an operator could
     * set the two to disagree.
     */

    /**
     * The seller's identity as it must appear on a tax invoice.
     *
     * A document titled "Tax Invoice" that does not carry the seller's tax registration number is
     * not one: under the VAT Law 67/2016 executive regulations the invoice must name the supplier
     * and their registration number, and **a tenant cannot support an input-VAT deduction without
     * it**. Atriom printed the property's name, address and city — and nothing else — until
     * 2026-08-12.
     *
     * Here rather than on `Asset` because Eltizam is ONE legal entity operating several malls; the
     * seller is the operator, not the building. If a second legal entity ever issues invoices for
     * its own properties, this becomes a per-asset override — not a second copy of this field.
     *
     * **Defaults to empty, deliberately.** A placeholder TRN on a tax invoice is worse than a
     * missing one: it looks valid, gets filed by the tenant, and fails on audit. The PDF prints the
     * line only when this is set, so an unconfigured install is silently incomplete rather than
     * confidently wrong. It is a go-live gate item (docs/GO-LIVE.md).
     *
     * NOTE — the same number also lives on `EtaSettings::issuer_tax_registration_number`, which
     * predates this and is the e-invoicing submission's copy. **One number, two homes is a defect**
     * (an operator can set them to disagree, and then the PDF and the submission would too), but
     * collapsing them means editing the ETA module, which is frozen. When that freeze lifts, this
     * field is the survivor: a seller's registration number is company identity, not a property of
     * an integration that may be switched off.
     */
    public string $seller_tax_registration_number = '';

    /** The registered legal name, when it differs from the trading name on the property. */
    public string $seller_legal_name = '';

    public bool $wht_enabled = false;

    /** Percentage withheld from a vendor payment when the vendor has no agreed rate of its own. */
    public float $wht_default_rate = 0.0;

    public static function group(): string
    {
        return 'tax';
    }
}
