<?php

namespace App\Support;

use App\Models\ExpenseCategory;

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

    /**
     * The nature of a category — the ROW first, this map as the floor, `variable` beyond both.
     *
     * The map below was the only answer, so a cost the operator added (insurance, government fees,
     * bank charges) was silently `variable` and apportioned through the CAM pool as though it moved
     * with occupancy. Insurance does not. A row can now say so.
     */
    public static function forCategory(?string $category): string
    {
        return ExpenseCategory::natureFor($category)
            ?? self::MAP[$category]
            ?? self::VARIABLE;
    }

    /**
     * The categories of a given nature — the map read in reverse, so the two directions can't
     * drift. Returns [] for an unknown nature (callers guard against an empty whereIn).
     *
     * @return string[]
     */
    public static function categoriesOf(string $nature): array
    {
        // The catalogue FIRST, then the floor — the same resolution `forCategory()` performs, read
        // backwards. Reading only the const here would have re-broken the very property this
        // method's docblock claims: a category the operator marked `fixed` answers `fixed` going
        // one way and is absent going the other, so a CAM pool filtered by nature would silently
        // omit it while the cost itself was classified correctly.
        $floor = array_filter(self::MAP, fn (string $n) => $n === $nature);

        try {
            $rows = ExpenseCategory::query()->pluck('cost_nature', 'code')->all();
        } catch (\Throwable) {
            $rows = [];
        }

        // A row overrides the floor for its own code, in both directions.
        $resolved = array_merge($floor, array_filter($rows, fn (?string $n) => $n === $nature));

        foreach ($rows as $code => $rowNature) {
            if ($rowNature !== $nature) {
                unset($resolved[$code]);
            }
        }

        return array_keys($resolved);
    }
}
