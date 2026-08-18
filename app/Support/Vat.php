<?php

namespace App\Support;

use App\Models\ChargeCode;
use App\Models\TaxCode;
use Carbon\CarbonInterface;

/**
 * The one resolver every origination point calls to find out what tax a line bills.
 *
 * **What this replaced, twice.** The rate was first a literal `14` repeated across eight
 * origination points — `BillMeterReadingService::VAT_RATE`, the service charge seeded onto a new
 * lease, the CAM admin fee (charge line, invoice line, and a bare `* 0.14`), and the invoice-line
 * form's default plus its type-switch. Egypt moved this rate once already (10% → 14% in 2017), and
 * the next move would have meant finding all eight. That became `TaxSettings::vat_standard_rate`:
 * one number, one place — and still the wrong shape, because **a rate has a date** and a settings
 * field cannot carry one. It is now a rung on a {@see TaxCode}'s ladder.
 *
 * **What is deliberately NOT here.** Only *origination* reads this. Once a charge or invoice line
 * exists it carries its own `vat_rate` column, and every downstream path (the monthly billing run,
 * renewal, rent changes, credit notes) reads that stored figure. That is the correct behaviour and
 * must not be "simplified" into re-resolving: an invoice issued at 14% stays a 14% document
 * forever. Changing a rate affects what is billed NEXT, never what was already billed — otherwise a
 * rate change would silently rewrite history and de-tie the books from the filed returns.
 *
 * ## Three layers, and each earns its place
 *
 *   1. **`charge_codes.tax_code`** — which tax this supply is billed under. The accountant's
 *      ruling, saved as a row, no deploy. Yardi puts the same decision in the same place: a `Tax`
 *      flag on the charge code.
 *   2. **`tax_codes` + `tax_rates`** — what that tax charges, and since when. Master data, because
 *      a rate has a validity period and a GL account.
 *   3. **{@see EXEMPT_TYPES} and {@see DEFAULT_STANDARD_RATE}** — the FLOOR, for a database whose
 *      catalogue is not seeded yet.
 *
 * ## The date is a parameter, not "now"
 *
 * {@see rateForType()} takes the date the document is being originated for. Pass it. An invoice
 * back-dated into a previous rate regime must bill that regime's rate, and a rate the accountant
 * entered in advance must start applying by itself on the day it takes effect. Omitting the
 * argument means today, which is right for the many callers originating something now.
 *
 * **Exempt is not zero-rated, but both bill 0.** They differ on the VAT return, so the distinction
 * is stored on the tax code rather than inferred from a zero on a line, where it would be
 * unrecoverable.
 */
class Vat
{
    /**
     * Supplies outside the scope of VAT — base rent, percentage rent, late fees, violation fines,
     * the marketing levy. Named so a rate change can never sweep them up.
     */
    public const EXEMPT = 0.0;

    /**
     * The standard rate an UNSEEDED database bills.
     *
     * The floor under {@see standardRate()}, and the same 14% the catalogue seeds — asserted here
     * as a constant for the same reason {@see EXEMPT_TYPES} is: a fresh deployment before its
     * seeders run, or one of the many tests that seed no catalogue, must not fall through to zero
     * and silently under-collect a tax that is due. `TaxCatalogueConformanceTest` asserts this and
     * the seeded ladder agree, so the floor is a safety net and never a second opinion.
     */
    public const DEFAULT_STANDARD_RATE = 14.0;

    /**
     * The tax code the catalogue seeds for the standard rate.
     *
     * Named as the operator's own sheet names it ("14%"), not as a developer would ("VAT_STD") —
     * the catalogue is their reference document as much as the engine's lookup table.
     */
    public const STANDARD_TAX_CODE = 'VAT_14';

    /**
     * The FLOOR: which codes are out of scope when the catalogue cannot answer.
     *
     * **Taxability lives on `charge_codes.tax_code`, not here.** An accountant rules on it and saves
     * a row; {@see rateForType()} reads that row. This list is what an *unseeded* database bills —
     * a fresh deployment before its seeders run, or one of the many tests that seed no catalogue —
     * and it exists for one reason: without it, an empty catalogue would make `rateForType()` fall
     * through to the standard rate and charge 14% VAT on base rent.
     *
     * Exactly the arrangement `InvoiceJournalizer::REVENUE_ROLE` uses for posting roles: catalogue
     * first, hard-coded map as a floor, fallback last, with a conformance test asserting the two
     * agree code-for-code so the floor is a safety net and never a second opinion
     * (`ChargeCodeVatTreatmentConformanceTest`).
     *
     * It is also, still, the answer to the drift that created it: every service originating one of
     * these lines and the invoice form's type-switch resolve through {@see rateForType()}, so a Late
     * Fee raised by hand and one raised by `LateFeeService` cannot be taxed differently.
     *
     * @var array<int, string>
     */
    public const EXEMPT_TYPES = [
        'base_rent',
        'percentage_rent',
        'late_fee',
        'marketing',
        'violation_fine',
        'nsf_fee',
        // Parking was exempt by way of a settings toggle that shipped off, until taxability became
        // a column. It belongs in the floor for the same reason as the rest: without it, a database
        // with no catalogue would bill a bay at the standard rate while a seeded one bills nothing.
        'parking',
        // A deposit is a SECURITY, not consideration for a supply — nothing is being sold, and the
        // money is owed back. Taxing it would charge VAT on the landlord's own liability.
        'security_deposit',
    ];

    /**
     * The rate a NEW line of charge code `$type` originates at, for a document dated `$on`.
     *
     * Resolution order:
     *   1. the charge code's `tax_code` → that tax's rate in force on `$on` — the accountant's
     *      ruling, editable without a deploy;
     *   2. {@see EXEMPT_TYPES}, when the catalogue has no row for this charge code, or has one that
     *      nobody has classified yet (unseeded database, or an operator-added code);
     *   3. the standard rate, for a code nobody has classified — assume taxable rather than
     *      silently under-collect a tax that is due.
     *
     * ORIGINATION ONLY. The billing run, renewals and credit notes read the rate stored on the
     * charge or invoice line, so changing a tax code never re-rates an issued document.
     */
    public static function rateForType(?string $type, CarbonInterface|string|null $on = null): float
    {
        if ($type === null) {
            return self::standardRate($on);
        }

        $taxCode = ChargeCode::taxCodeFor($type);

        // No catalogue row, or a row nobody has classified: both mean the same thing here — nothing
        // has ruled on this supply, so the floor decides.
        if ($taxCode === null) {
            return in_array($type, self::EXEMPT_TYPES, true)
                ? self::EXEMPT
                : self::standardRate($on);
        }

        $rate = TaxCode::rateOn($taxCode, $on);

        // The charge code names a tax the catalogue does not hold, or holds with an empty ladder.
        // Falling back to the floor keeps an exempt supply exempt rather than taxing it because a
        // tax code was renamed or deactivated out from under it.
        if ($rate === null) {
            return in_array($type, self::EXEMPT_TYPES, true)
                ? self::EXEMPT
                : self::standardRate($on);
        }

        return max(0.0, $rate);
    }

    /**
     * The standard rate on `$on`, as a percentage (14.0 = 14%).
     *
     * Maintained at /admin/tax-codes → VAT — standard rate, as a dated ladder.
     */
    public static function standardRate(CarbonInterface|string|null $on = null): float
    {
        $rate = TaxCode::rateOn(self::STANDARD_TAX_CODE, $on);

        // A negative rate would produce a negative VAT line — a credit hiding inside a tax figure.
        // Clamp rather than throw: billing must not stop because a rate was mistyped.
        return max(0.0, $rate ?? self::DEFAULT_STANDARD_RATE);
    }

    /** VAT due on a taxable supply of `$amount` at the standard rate. */
    public static function on(float $amount, CarbonInterface|string|null $at = null): float
    {
        return self::atRate($amount, self::standardRate($at));
    }

    /**
     * VAT due on `$amount` billed under charge code `$type`, for a document dated `$on` — the
     * standard rate unless the catalogue says this supply is exempt, zero-rated, or on a schedule
     * rate of its own.
     *
     * Use this, not {@see on()}, wherever the money being taxed belongs to a known charge code:
     * `on()` cannot see the accountant's ruling and will tax an exempt supply.
     */
    public static function onType(float $amount, string $type, CarbonInterface|string|null $on = null): float
    {
        return self::atRate($amount, self::rateForType($type, $on));
    }

    /** VAT due on `$amount` at an explicitly stored rate (a frozen document or pool rate). */
    public static function atRate(float $amount, float $rate): float
    {
        return round($amount * max(0.0, $rate) / 100, 2);
    }
}
