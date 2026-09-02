<?php

namespace App\Support;

/**
 * Whether an account's result sits ABOVE or BELOW the net-operating-income line.
 *
 * ## Why a property P&L has a line in the middle of it
 *
 * A general income statement runs revenue − expenses = profit. A REAL-ESTATE income statement stops
 * halfway and states **Net Operating Income** — what the mall itself earns, before the financing and
 * accounting layers that belong to whoever happens to own it:
 *
 *     Operating revenue      rent, service charge, CAM recovery, parking, percentage rent
 *   − Operating expenses     cleaning, security, R&M, utilities, salaries, bad debt
 *   = NET OPERATING INCOME
 *   ± Below the line         depreciation, interest, bank charges, gains/losses on disposal
 *   = Net profit
 *
 * NOI is the number an owner, a valuer and a lender all read, because a property is worth roughly
 * its NOI divided by a cap rate. Two malls with identical NOI are worth the same whether one is
 * mortgaged and the other is not, and whether one depreciates over 25 years and the other over 40 —
 * which is precisely what the items below the line differ on. Yardi, MRI and Entrata all print this
 * subtotal; without it the statement mixes the cost of cleaning the floors in with the cost of
 * borrowing against them.
 *
 * ## The account says which it is — never the code
 *
 * This is the same finding as {@see CashFlowSection}, which this class is deliberately shaped after:
 * classifying by literal code prefix is correct about the chart we happen to ship and silently wrong
 * about the operator's own, which is still pending. Here the danger is sharper, because ONE prefix
 * genuinely holds both answers — in the shipped chart `42101` Miscellaneous Income is property
 * income and belongs in NOI, while `42102` Gain on Disposal of Assets is a one-off that must not,
 * and a rule reading `42` cannot tell them apart. Prefixes survive only in
 * {@see forShippedChart()}, which is a statement about OUR chart used to backfill the column.
 *
 * ## The floor is OPERATING, and it errs in the safe direction
 *
 * An account nobody has classified counts inside NOI. That understates NOI (a below-the-line cost is
 * carried above the line) and leaves **net profit exactly right**, because the bottom line is
 * computed from the full revenue and expense sets and never from these buckets. Erring the other way
 * would overstate the number a valuation is built on, which is the one direction that costs money.
 *
 * ## Only revenue and expense carry one
 *
 * A balance-sheet account has no result to place. The form hides the field for them, the seeder
 * leaves it null, and {@see forShippedChart()} returns null — the mirror of the rule
 * `CashFlowSection` states in the opposite direction.
 */
final class StatementSection
{
    /** Above the line: the property's own trading result, which NOI is made of. */
    public const OPERATING = 'operating';

    /**
     * Below the line: real results that are not the property's trading performance.
     *
     * Depreciation and amortisation (an accounting allocation, not a cash cost of running the mall),
     * interest and bank charges (the cost of the OWNER's borrowing, not of the asset), and gains or
     * losses on disposal (one-off, and about a decision to sell rather than about trading).
     */
    public const NON_OPERATING = 'non_operating';

    /** @var list<string> */
    public const SECTIONS = [self::OPERATING, self::NON_OPERATING];

    /**
     * Where this account's result belongs, given what it says.
     *
     * The floor is OPERATING for the reason in the class docblock: it understates NOI and leaves net
     * profit untouched, which is the survivable error.
     */
    public static function for(?string $stored, string $type): string
    {
        if ($stored !== null && in_array($stored, self::SECTIONS, true)) {
            return $stored;
        }

        return self::OPERATING;
    }

    /**
     * What OUR shipped chart's codes mean — used to backfill the column, and by the seeder.
     *
     * The one place the prefixes are allowed to live, and only because they are correct *about this
     * chart*: writing today's answer down explicitly is what frees every reader from having to
     * re-derive it. Another operator's chart gets classified by their accountant on the screen.
     *
     * Order matters: `42102` is tested before anything broader could claim it, for the reason the
     * class docblock gives — it is the row that proves a prefix rule cannot do this job.
     */
    public static function forShippedChart(string $code, string $type): ?string
    {
        // A balance-sheet account has no result to place on an income statement.
        if (! in_array($type, ['revenue', 'expense'], true)) {
            return null;
        }

        // Gain on disposal (42102) — a one-off, and it sits INSIDE `42 Other Income` beside
        // `42101 Miscellaneous Income`, which is ordinary property income and stays in NOI.
        if (str_starts_with($code, '42102')) {
            return self::NON_OPERATING;
        }

        // Depreciation (51107) — inside `51 Operating Expenses`, and excluded from NOI by
        // definition: it is an allocation of a past purchase, not a cost of trading this month.
        // The mirror image of the same account's cash-flow treatment, where it is an add-back.
        if (str_starts_with($code, '51107')) {
            return self::NON_OPERATING;
        }

        // `52 Other Expenses` — bank charges, loss on disposal, bank commission, interest. Financing
        // and one-off costs, all of them below the line.
        if (str_starts_with($code, '52')) {
            return self::NON_OPERATING;
        }

        // Everything else trades: rent, recoveries, percentage rent, parking, miscellaneous income,
        // and — deliberately — `43 Sales Returns & Allowances` and `51109 Bad Debt Expense`. Credit
        // loss is a cost of letting shops to retailers, so both reduce NOI rather than sitting under
        // it. Irrecoverable stamp and schedule tax (51111) are likewise a cost of doing business
        // here, not a financing charge.
        return self::OPERATING;
    }
}
