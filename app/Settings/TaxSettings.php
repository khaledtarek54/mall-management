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
    /*
     * NO RATES LIVE ON THIS CLASS. That is the point of it now.
     *
     * `vat_standard_rate` was here until 2026-08-12 and is now a dated rung on the `VAT_STD` tax
     * code (`tax_codes` + `tax_rates`), read through `App\Support\Vat`. A settings field cannot
     * carry the one property a rate must have — the day it came into force — so editing it re-rated
     * every document originated afterwards, including one back-dated into the previous regime, and
     * it could not express a rate change announced in advance.
     *
     * WHICH supplies a rate applies to is not here either: that is `charge_codes.tax_code`, the
     * accountant's ruling saved as a row. Parking had a toggle of its own on this class until
     * 2026-08-11 — the same question answered in a second place, where an operator could set the
     * two to disagree.
     *
     * What belongs here is POLICY that has no rate and no date: the seller's identity on a tax
     * invoice, and whether withholding is switched on at all.
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
     * confidently wrong. It is a go-live gate item (docs/operations/GO-LIVE.md).
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

    /**
     * Where a tenant writes about a bill. Printed on invoices and statements; the line is OMITTED
     * when this is blank, because a contact that does not exist is worse than none — it is trusted,
     * used, and fails silently. (Until 2026-08-21 these documents printed `billing@…​.test`,
     * fabricated in the template from the property's name.)
     */
    public string $seller_billing_email = '';

    public bool $wht_enabled = false;

    /**
     * The withholding tax CODE assumed for a supplier whose own nature has not been ruled on.
     *
     * A code, not a percentage — the rate it carries lives in the catalogue with every other rate.
     * What belongs here is the policy question settings exist for: *which nature do we assume by
     * default*. Empty by design: supplies, services, contracting and professional fees all differ,
     * so there is no defensible default, and an empty one withholds nothing.
     */
    public string $wht_default_tax_code = '';

    public static function group(): string
    {
        return 'tax';
    }
}
