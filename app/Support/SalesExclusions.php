<?php

namespace App\Support;

/**
 * **What comes OFF a tenant's reported turnover before percentage rent is worked out.**
 *
 * Every retail lease defines "Gross Sales" with an exclusion list, and the list is the most disputed
 * clause in retail leasing. Some of it is universally agreed — nobody argues that the VAT a shop
 * collects for the state was ever its own money — and some is negotiated tenant by tenant.
 *
 * **Why this exists at all.** `declared_sales` was one number with no stated basis: nothing anywhere
 * said whether it was gross or net of anything. If tenants report the VAT-inclusive figure their POS
 * prints by default, percentage rent is over-charged — and because the breakpoint is subtracted
 * first, a 14% error in sales becomes a far larger error in what is billed. On a 12,000,000
 * breakpoint at 7%, sales of 15,000,000 owe 210,000; the same sales reported VAT-inclusive owe
 * 357,000. **A 70% over-charge, from a number nobody recorded the meaning of.**
 *
 * The defect was never a wrong figure. It was an *unknowable* one — and this makes it recorded.
 *
 * **Yardi's shape, adapted.** Voyager has no "VAT exclusion" feature: it reports sales by CATEGORY
 * and a category is simply flagged not-included. Categories also carry their own rates (food vs
 * merchandise), which is the heavier half and is not built — see module 09. This registry is the
 * exclusion half on its own, which is what answers the money question.
 */
class SalesExclusions
{
    /**
     * The catalogue. Ordered as a sales certificate reads: the statutory deduction first, then the
     * ones that unwind a sale, then the negotiated conveniences.
     *
     * `other` exists because an exclusion list is negotiated and no fixed catalogue can be complete
     * — an operator recording a real clause must not have to mislabel it.
     */
    public const TYPES = [
        'vat',
        'returns',
        'gift_cards',
        'inter_store_transfers',
        'employee_discounts',
        'delivery_and_services',
        'other',
    ];

    /**
     * What a lease allows by default when its clause has not been abstracted yet.
     *
     * VAT alone, deliberately: it is the one deduction that is not a concession — the money was
     * never the tenant's — while every other line here is something a landlord GRANTED and must
     * therefore have agreed to. Defaulting to more would credit tenants with exclusions nobody
     * negotiated.
     */
    public const ALWAYS_ALLOWED = ['vat'];

    /** @return array<int, string> */
    public static function types(): array
    {
        return self::TYPES;
    }

    public static function isKnown(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * Translated labels, keyed by type — for a picker, and for the certificate an operator reads.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::TYPES as $type) {
            $options[$type] = __('admin.sales_exclusions.'.$type);
        }

        return $options;
    }

    /**
     * The VAT contained in a VAT-INCLUSIVE figure: `gross − gross ÷ (1 + rate)`.
     *
     * Not `gross × rate`, which is the mistake this method exists to prevent — that computes the VAT
     * ON the figure rather than the VAT already IN it, and over-deducts by a factor of (1 + rate).
     * At 14% the difference on 5,000,000 is 700,000 against 614,035.
     */
    public static function vatWithin(float $grossInclusive, ?float $rate = null): float
    {
        $rate = $rate ?? Vat::standardRate();

        if ($rate <= 0 || $grossInclusive <= 0) {
            return 0.0;
        }

        return round($grossInclusive - ($grossInclusive / (1 + ($rate / 100))), 2);
    }

    /**
     * Total the itemised exclusions, ignoring anything not in the catalogue.
     *
     * @param  array<string, mixed>|null  $exclusions
     */
    public static function total(?array $exclusions): float
    {
        if (! is_array($exclusions)) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($exclusions as $type => $amount) {
            if (self::isKnown((string) $type)) {
                $total += max(0.0, (float) $amount);
            }
        }

        return round($total, 2);
    }
}
