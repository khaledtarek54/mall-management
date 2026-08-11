<?php

namespace App\Support;

use App\Models\ChargeCode;
use App\Settings\TaxSettings;

/**
 * The standard Egyptian VAT rate — one place, owned by the operator's accountant.
 *
 * **What this replaced.** The rate was a literal `14` repeated across eight origination points:
 * `BillMeterReadingService::VAT_RATE`, the service charge seeded onto a new lease, the CAM admin
 * fee (charge line, invoice line, and a bare `* 0.14`), and the invoice-line form's default plus
 * its type-switch. Egypt moved this rate once already — 10% → 14% in 2017 — and the next move would
 * have meant finding all eight, with no single place that states what the rate is.
 *
 * **What is deliberately NOT here.** Only *origination* reads this. Once a charge or invoice line
 * exists it carries its own `vat_rate` column, and every downstream path (the monthly billing run,
 * renewal, rent changes, credit notes, the ETA payload) reads that stored figure. That is the
 * correct behaviour and must not be "simplified" into reading the current setting: an invoice
 * issued at 14% stays a 14% document forever. Changing the setting affects what is billed NEXT,
 * never what was already billed — otherwise a rate change would silently rewrite history and
 * de-tie the books from the filed returns.
 *
 * **The RATE is here; WHICH supplies are taxable is not.** That belongs to the charge-code
 * catalogue (`charge_codes.vat_treatment`, standard / exempt / zero-rated, with an optional
 * per-code rate override) — an accountant's ruling, saved as a row, no deploy. This class resolves
 * it: {@see rateForType()} is the one function every origination point calls, so a Late Fee raised
 * by a service and one typed by hand cannot be taxed differently. Yardi puts the same decision in
 * the same place — a `Tax` flag on the charge code, with the rate configured as data.
 *
 * **Exempt is not zero-rated, but both bill 0.** They differ on the VAT return, so the distinction
 * is stored on the code rather than inferred from a zero on a line, where it would be unrecoverable.
 * {@see EXEMPT_TYPES} remains as the floor for a database whose catalogue is not seeded yet.
 *
 * Per-supply overrides that legitimately differ (a CAM pool's `recovery_vat_rate`, frozen with its
 * basis at reconciliation time) resolve their own stored rate and only fall back to this default
 * when a new record is created.
 */
class Vat
{
    /**
     * Supplies outside the scope of VAT — base rent, percentage rent, late fees, violation fines,
     * the marketing levy. Named so a rate change can never sweep them up.
     */
    public const EXEMPT = 0.0;

    /**
     * The FLOOR: which codes are out of scope when the catalogue cannot answer.
     *
     * **Taxability lives on `charge_codes.vat_treatment`, not here.** An accountant rules on it and
     * saves a row; {@see rateForType()} reads that row. This list is what an *unseeded* database
     * bills — a fresh deployment before its seeders run, or one of the many tests that seed no
     * catalogue — and it exists for one reason: without it, an empty `charge_codes` table would
     * make `rateForType()` fall through to the standard rate and charge 14% VAT on base rent.
     *
     * Exactly the arrangement `InvoiceJournalizer::REVENUE_ROLE` uses for posting roles: catalogue
     * first, hard-coded map as a floor, fallback last, with a conformance test asserting the two
     * agree code-for-code so the floor is a safety net and never a second opinion
     * (`ChargeCodeVatTreatmentConformanceTest`).
     *
     * It is also, still, the answer to the drift that created it: every service originating one of
     * these lines and the invoice form's type-switch now resolve through `rateForType()`, so a Late
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
    ];

    /**
     * The rate a NEW line of `$type` originates at.
     *
     * Resolution order, and each step earns its place:
     *   1. the charge-code catalogue — the accountant's ruling, editable without a deploy;
     *   2. {@see EXEMPT_TYPES}, when the catalogue has no row for this code (unseeded database);
     *   3. the standard rate, for a code nobody has classified — assume taxable rather than
     *      silently under-collect a tax that is due.
     *
     * ORIGINATION ONLY. The billing run, renewals and credit notes read the rate stored on the
     * charge or invoice line, so changing a treatment never re-rates an issued document.
     */
    public static function rateForType(?string $type): float
    {
        if ($type === null) {
            return self::standardRate();
        }

        $policy = ChargeCode::vatPolicyFor($type);

        if ($policy === null) {
            return in_array($type, self::EXEMPT_TYPES, true)
                ? self::EXEMPT
                : self::standardRate();
        }

        if ($policy['treatment'] !== ChargeCode::VAT_STANDARD) {
            // Exempt and zero-rated both bill nothing. They differ on the VAT return, which reads
            // the treatment on the code, not the zero on the line.
            return self::EXEMPT;
        }

        return $policy['override'] !== null
            ? max(0.0, $policy['override'])
            : self::standardRate();
    }

    /** The standard rate, as a percentage (14.0 = 14%). Configured at /admin/settings → Tax. */
    public static function standardRate(): float
    {
        $rate = (float) app(TaxSettings::class)->vat_standard_rate;

        // A negative rate would produce a negative VAT line — a credit hiding inside a tax figure.
        // Clamp rather than throw: billing must not stop because a setting was mistyped.
        return max(0.0, $rate);
    }

    /** VAT due on a taxable supply of `$amount` at the standard rate. */
    public static function on(float $amount): float
    {
        return round($amount * self::standardRate() / 100, 2);
    }

    /**
     * VAT due on `$amount` billed under charge code `$type` — the standard rate unless the
     * catalogue says this supply is exempt, zero-rated, or on a schedule rate of its own.
     *
     * Use this, not {@see on()}, wherever the money being taxed belongs to a known charge code:
     * `on()` cannot see the accountant's ruling and will tax an exempt supply.
     */
    public static function onType(float $amount, string $type): float
    {
        return self::atRate($amount, self::rateForType($type));
    }

    /** VAT due on `$amount` at an explicitly stored rate (a frozen document or pool rate). */
    public static function atRate(float $amount, float $rate): float
    {
        return round($amount * max(0.0, $rate) / 100, 2);
    }
}
