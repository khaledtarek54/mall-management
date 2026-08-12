<?php

namespace App\Support;

use App\Models\TaxCode;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Settings\TaxSettings;
use Carbon\CarbonInterface;

/**
 * Egyptian withholding tax on supplier payments — خصم وإضافة (module 12b).
 *
 * Under Income Tax Law 91/2005 art. 59 an Egyptian entity must withhold a percentage of what it pays
 * a local supplier and remit it to the ETA. Paying gross leaves the operator liable for the amount
 * they failed to withhold, so this is a compliance obligation, not an optional deduction.
 *
 * **No rate is written here, or typed anywhere.** The statutory rates differ by the nature of the
 * payment — supplies, services, contracting, professional fees — they are revised from time to time,
 * and the operator's accountant owns the correct figure. Until 2026-08-12 that reasoning was honoured
 * by putting the number in a settings field and a per-vendor percentage box, which is the same guess
 * one level down: a free box invites a made-up figure that then looks official. The rates now come
 * from the operator's own catalogue, which lists withholding at four (0.5 · 1 · 3 · 5%), and a
 * supplier is POINTED at whichever the accountant rules applies.
 *
 * Resolution order for a vendor's rate:
 *   1. `vendors.withholding_exempt` — this supplier is outside Egyptian withholding. Withhold
 *      nothing, whatever the default says. A flag rather than a `WH_0` code because the operator's
 *      sheet has no zero withholding rate, and not withholding is the absence of a tax rather than
 *      a tax of nothing.
 *   2. `vendors.withholding_tax_code` — the code agreed with this supplier.
 *   3. `TaxSettings::wht_default_tax_code` — which nature we assume when theirs is unruled.
 *   4. Zero — withhold nothing rather than invent a rate.
 *
 * **The catalogue stores withholding rates NEGATIVE** (the operator's sheet writes "WH -1%", because
 * the tax comes off what is paid rather than adding to it). Everything here works in magnitudes —
 * an amount to deduct — so the sign is dropped on the way in. Handing a negative rate to
 * {@see on()} would return a negative deduction and quietly pay the supplier MORE.
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

    /** The code assumed for a supplier whose own nature has not been ruled on; '' = none. */
    public static function defaultTaxCode(): string
    {
        return trim((string) app(TaxSettings::class)->wht_default_tax_code);
    }

    /** The rate the default code carries, as a positive percentage. */
    public static function defaultRate(CarbonInterface|string|null $on = null): float
    {
        return self::rateOfCode(self::defaultTaxCode(), $on);
    }

    /** The withholding code that applies to THIS vendor, or null when none does. */
    public static function taxCodeFor(?Vendor $vendor): ?string
    {
        if ($vendor?->withholding_exempt) {
            return null;
        }

        $code = trim((string) ($vendor?->withholding_tax_code ?? ''));

        return $code !== '' ? $code : (self::defaultTaxCode() ?: null);
    }

    /**
     * The rate that applies to THIS vendor, as a positive percentage. Zero when withholding is off.
     *
     * Resolved for `$on` — the payment's date — because a withholding rate has a validity period
     * like every other rate in the catalogue, and a back-dated payment must withhold what was due
     * when it was made.
     */
    public static function rateFor(?Vendor $vendor, CarbonInterface|string|null $on = null): float
    {
        if (! self::enabled()) {
            return 0.0;
        }

        return self::rateOfCode(self::taxCodeFor($vendor), $on);
    }

    /**
     * A code's rate as a magnitude.
     *
     * `abs()` because the catalogue stores withholding negative — the sheet's own notation for
     * "deducted, not added". Every caller here wants an amount to subtract.
     */
    private static function rateOfCode(?string $code, CarbonInterface|string|null $on = null): float
    {
        if ($code === null || $code === '') {
            return 0.0;
        }

        return abs(TaxCode::rateOn($code, $on) ?? 0.0);
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
    public static function on(float $amount, ?Vendor $vendor, CarbonInterface|string|null $on = null): float
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            return 0.0;
        }

        return min($amount, round($amount * self::rateFor($vendor, $on) / 100, 2));
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
    public static function onBillPayment(float $payment, VendorBill $bill, CarbonInterface|string|null $on = null): float
    {
        return self::on(self::vatExclusiveShareOf($payment, $bill), $bill->vendor, $on);
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
