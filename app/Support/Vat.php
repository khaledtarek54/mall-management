<?php

namespace App\Support;

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
 * **Exempt is not zero-rated, but both store 0 here.** Base rent, percentage rent, late fees,
 * violation fines and the marketing levy are outside the scope of VAT on a taxable supply, so they
 * originate at `EXEMPT`. They must never pick up the standard rate if it changes — hence a named
 * constant rather than a bare 0 at each call site.
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

    /** VAT due on `$amount` at an explicitly stored rate (a frozen document or pool rate). */
    public static function atRate(float $amount, float $rate): float
    {
        return round($amount * max(0.0, $rate) / 100, 2);
    }
}
