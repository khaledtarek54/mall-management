<?php

namespace App\Support;

use App\Models\Vendor;
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
     * Tax to withhold from a gross payment of `$amount` to `$vendor`.
     *
     * Clamped to the payment itself: a mis-set rate above 100% must never produce a negative
     * cash movement, which would silently reverse the direction of the bank posting.
     */
    public static function on(float $amount, ?Vendor $vendor): float
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            return 0.0;
        }

        return min($amount, round($amount * self::rateFor($vendor) / 100, 2));
    }
}
