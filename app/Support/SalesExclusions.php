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
     * Read one exclusion amount the way a person typed it (SW-164).
     *
     * `(float) '1,200.00'` is **1.0** in PHP — it stops at the comma. The exclusions field is a free
     * `KeyValue`, so an operator typing a thousands separator (the ordinary way to write money, and
     * what a POS report prints) silently deducted **one pound** instead of 1,200 and the tenant was
     * billed percentage rent on turnover that was never theirs. Nothing said so: the figure is
     * summed into a derived total and the screen shows the total, not the parse.
     *
     * Arabic-Indic digits are folded too, because the panel is bilingual and a number typed on an
     * Arabic keyboard is a number.
     */
    public static function amount(mixed $raw): float
    {
        if (is_numeric($raw)) {
            return max(0.0, (float) $raw);
        }

        $text = trim((string) $raw);

        if ($text === '') {
            return 0.0;
        }

        $text = strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '.', '٬' => '',
        ]);

        // Strip grouping and anything that is not part of a decimal number. A minus is dropped with
        // it: an exclusion is an amount deducted, and the sign is the operation, not the figure —
        // `max(0, …)` said the same thing already and this keeps `-1,200` from reading as -1.
        $text = preg_replace('/[^0-9.]/', '', $text) ?? '';

        return $text === '' ? 0.0 : max(0.0, (float) $text);
    }

    /**
     * The exclusion keys this list uses that the catalogue does not know (SW-164).
     *
     * `total()` skips them, so a mistyped or invented key — `VAT`, `refunds`, `sales returns` —
     * is silently worth nothing and the tenant is over-billed by exactly that deduction. Returning
     * them lets the write REFUSE and name them, which is the safe direction: `other` exists
     * precisely so an operator recording a real clause never has to invent a key.
     *
     * @param  array<string, mixed>|null  $exclusions
     * @return array<int, string>
     */
    public static function unknownKeys(?array $exclusions): array
    {
        if (! is_array($exclusions)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', array_keys($exclusions)),
            fn (string $type) => $type !== '' && ! self::isKnown($type),
        ));
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
                // `amount()`, never a bare cast — see its docblock: `(float) '1,200.00'` is 1.0.
                $total += self::amount($amount);
            }
        }

        return round($total, 2);
    }
}
