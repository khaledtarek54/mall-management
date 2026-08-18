<?php

namespace App\Support;

use App\Services\Accounting\TaxDepreciationService;

/**
 * The Egyptian income-tax depreciation pools — Law 91/2005, Article 25.
 *
 * **Why a second basis exists at all.** The accounting book depreciates straight-line over a useful
 * life the operator chooses (`DepreciationService`, module 23) — that is the EAS figure, and it is
 * what the financial statements report. The TAX figure is a different calculation entirely: fixed
 * statutory rates, and for most assets a **pooled diminishing-value** base rather than per-asset
 * straight line. Until this existed no tax-basis depreciation figure could be produced at all, so
 * the corporate return could not be prepared from this system however complete the register was.
 *
 * **It is a SCHEDULE, not a second ledger.** Egypt files single-book: the statutory accounts stay
 * on the accounting basis and the tax figure is a computation attached to the return. So nothing
 * here posts a journal entry, and there is no second set of accumulated-depreciation balances to
 * keep reconciled. The difference between the two bases is a temporary difference an accountant
 * reads off the comparison — which is exactly what they need and all they need.
 *
 * **The rates are STATUTE, not preference**, which is why they are a constant here rather than a
 * settings screen. An operator does not get to choose that computers depreciate at 50%; the law
 * says so. When the law changes, this constant changes with a dated note — the same treatment the
 * VAT catalogue gives a rate rung.
 *
 * @see TaxDepreciationService the computation itself
 */
class TaxDepreciation
{
    /** Buildings, establishments, constructions, ships and aircraft — 5% of COST, straight-line. */
    public const BUILDINGS = 'buildings';

    /** Intangibles, including purchased goodwill — 10% of COST, straight-line. */
    public const INTANGIBLES = 'intangibles';

    /** Computers, information systems, software and data storage — 50%, pooled diminishing value. */
    public const COMPUTERS = 'computers';

    /** Every other tangible asset — 25%, pooled diminishing value. */
    public const GENERAL = 'general';

    /** Land, and anything the law does not let you depreciate. */
    public const NONE = 'none';

    /**
     * Pool → annual rate as a PERCENTAGE.
     *
     * @var array<string, float>
     */
    public const RATES = [
        self::BUILDINGS => 5.0,
        self::INTANGIBLES => 10.0,
        self::COMPUTERS => 50.0,
        self::GENERAL => 25.0,
        self::NONE => 0.0,
    ];

    /**
     * Which pools are POOLED diminishing-value, and which are per-asset straight-line on cost.
     *
     * This is the distinction that makes a tax schedule different in KIND from the accounting one,
     * not merely different in rate. A pooled asset loses its individual identity: additions join
     * the pool, the rate applies to the whole written-down balance, and no single asset ever
     * reaches zero — it just becomes an ever-smaller share of a shrinking pool.
     *
     * @var array<int, string>
     */
    public const POOLED = [self::COMPUTERS, self::GENERAL];

    /** @return array<int, string> */
    public static function pools(): array
    {
        return array_keys(self::RATES);
    }

    public static function rateFor(?string $pool): float
    {
        return self::RATES[$pool] ?? 0.0;
    }

    public static function isPooled(?string $pool): bool
    {
        return in_array($pool, self::POOLED, true);
    }

    /**
     * The pool an asset falls in when nobody has said.
     *
     * `GENERAL` — the law's own residual category ("all other assets of the activity"), so an
     * unclassified asset is treated the way the statute treats one rather than being silently
     * dropped from the schedule. Assets the operator means to exclude must say `NONE` out loud.
     */
    public static function default(): string
    {
        return self::GENERAL;
    }
}
