<?php

namespace App\Support;

use App\Models\Vendor;
use App\Models\VendorBill;
use App\Settings\TaxSettings;

/**
 * Egyptian withholding tax on supplier payments — خصم وإضافة (module 12b).
 *
 * Under Income Tax Law 91/2005 art. 59 an Egyptian entity must withhold a percentage of what it pays
 * a local supplier and remit it to the ETA. Paying gross leaves the operator liable for the amount
 * they failed to withhold, so this is a compliance obligation, not an optional deduction.
 *
 * Every number here is SETTINGS-DRIVEN and never hardcoded: the statutory rates differ by the nature
 * of the payment (supplies, services, contracting, professional fees), they are revised by the ETA
 * from time to time, and the operator's accountant — not this codebase — owns the correct figure.
 * Shipping a guessed constant would be worse than shipping nothing, because it would look official.
 *
 * Resolution order for a vendor's rate:
 *   1. `vendors.withholding_tax_rate` — the per-supplier agreed rate. 0 means explicitly exempt
 *      (e.g. a foreign supplier outside Egyptian withholding), which is deliberately distinct
 *      from null ("nothing set for this supplier, use the default").
 *   2. `TaxSettings::wht_default_rate` (/admin/settings → Tax).
 *   3. Zero — withhold nothing rather than invent a rate.
 *
 * The whole feature is off until `TaxSettings::wht_enabled` is switched on, so existing books are
 * untouched until the operator's accountant has confirmed the rates.
 */
class WithholdingTax
{
    public static function enabled(): bool
    {
        return app(TaxSettings::class)->wht_enabled;
    }

    /** The default rate applied to a vendor with no agreed rate of its own. */
    public static function defaultRate(): float
    {
        return max(0.0, (float) app(TaxSettings::class)->wht_default_rate);
    }

    /** The rate that applies to THIS vendor, as a percentage. Zero when withholding is off. */
    public static function rateFor(?Vendor $vendor): float
    {
        if (! self::enabled()) {
            return 0.0;
        }

        // `?? ` not `?:` — an explicit 0 on the vendor is "exempt" and must not fall through
        // to the portfolio default.
        $rate = $vendor?->withholding_tax_rate !== null
            ? (float) $vendor->withholding_tax_rate
            : self::defaultRate();

        return max(0.0, $rate);
    }

    /**
     * Apply this vendor's rate to a base you have ALREADY made VAT-exclusive.
     *
     * **This is the primitive, and it is the easy one to misuse.** It applies the rate to whatever
     * number it is handed, so a caller passing a VAT-inclusive payment over-withholds — which is
     * exactly what happened: `recordPayment()` passed `min($amount, $bill->balance)`, derived from
     * `total`, until 2026-08-12. Use {@see onBillPayment()} when paying a bill; reach for this only
     * when the base is already net by construction.
     *
     * Clamped to the base: a mis-set rate above 100% must never produce a negative cash movement,
     * which would silently reverse the direction of the bank posting.
     */
    public static function on(float $amount, ?Vendor $vendor): float
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            return 0.0;
        }

        return min($amount, round($amount * self::rateFor($vendor) / 100, 2));
    }

    /**
     * Tax to withhold from a payment against a bill — **the one to call when paying a vendor**.
     *
     * **The Egyptian WHT base excludes VAT.** Withholding under Law 91/2005 art. 59 is a prepayment
     * of the supplier's INCOME tax, so it is charged on the consideration for the supply; the VAT
     * on top is the supplier's output tax, which they remit themselves. Withholding on it taxes a
     * tax. At 3% on a 100,000 net bill the difference is 3,420 withheld against 3,000 due — the
     * operator short-pays the vendor by 420 and over-remits the same to the ETA, on every bill.
     *
     * Mitigated only by `wht_enabled` being off, which is why this was worth fixing BEFORE the
     * accountant switches it on rather than discovering it in a vendor's first reconciliation.
     *
     * Works off the payment's VAT-exclusive SHARE rather than the bill's net total, so it stays
     * right for a partial payment: of a 57,000 payment on a 100,000 + 14,000 bill, 50,000 is
     * consideration and 7,000 is VAT. A bill with no VAT is unaffected (the share is the whole
     * payment), which is what makes this change invisible to every existing exempt supply.
     */
    public static function onBillPayment(float $payment, VendorBill $bill): float
    {
        return self::on(self::vatExclusiveShareOf($payment, $bill), $bill->vendor);
    }

    /**
     * The part of `$payment` that is consideration rather than VAT.
     *
     * Derived from the BILL's own tax composition (`subtotal` vs `subtotal + vat_amount`) rather
     * than from `total`, so an SLA penalty — which reduces the balance without touching either —
     * cannot distort the ratio.
     */
    public static function vatExclusiveShareOf(float $payment, VendorBill $bill): float
    {
        $payment = round($payment, 2);
        $net = round((float) $bill->subtotal, 2);
        $gross = round($net + (float) $bill->vat_amount, 2);

        if ($payment <= 0 || $gross <= 0) {
            return 0.0;
        }

        // No VAT on this bill (or a malformed one where VAT is negative): the whole payment is
        // consideration. Never scale UP — that would invent a base larger than the cash moving.
        if ($net >= $gross) {
            return $payment;
        }

        return round($payment * ($net / $gross), 2);
    }
}
