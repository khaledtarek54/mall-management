<?php

namespace App\Support;

/**
 * The fixed vs variable axis of an operating cost, keyed by category (FR-FIN-02).
 *
 * **Fixed** = a committed, recurring obligation that lands whether the mall is busy or not
 * (a security/cleaning contract, admin salaries/rent/subscriptions). **Variable** = spend that
 * tracks activity (utility consumption, ad-hoc repairs, discretionary marketing).
 *
 * Deliberately coarse — ONE nature per category — because the FRD asks for a fixed/variable
 * *report*, not per-line tagging. This is the single source of truth: `Expense`, `VendorBill`
 * (both carry the same category set) and the weekly-spend report all read it, so the split can
 * never disagree between the register and the report. Adjust the map as the operator's contracts
 * dictate; a category with no entry falls to `variable` (the conservative default — an
 * unclassified cost is not treated as a committed one).
 */
class CostNature
{
    public const FIXED = 'fixed';

    public const VARIABLE = 'variable';

    /** @var string[] */
    public const NATURES = [self::FIXED, self::VARIABLE];

    /** @var array<string,string> category => nature */
    public const MAP = [
        'cleaning_security' => self::FIXED,
        'admin' => self::FIXED,
        'maintenance' => self::VARIABLE,
        'utilities' => self::VARIABLE,
        'marketing' => self::VARIABLE,
        'other' => self::VARIABLE,
    ];

    /** The nature of a category — `variable` for an unmapped one. */
    public static function forCategory(?string $category): string
    {
        return self::MAP[$category] ?? self::VARIABLE;
    }

    /**
     * The categories of a given nature — the map read in reverse, so the two directions can't
     * drift. Returns [] for an unknown nature (callers guard against an empty whereIn).
     *
     * @return string[]
     */
    public static function categoriesOf(string $nature): array
    {
        return array_keys(array_filter(self::MAP, fn (string $n) => $n === $nature));
    }
}
