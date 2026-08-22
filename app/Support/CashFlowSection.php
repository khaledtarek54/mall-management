<?php

namespace App\Support;

/**
 * Where an account's movement belongs on the cash-flow statement (EG-28, finding S-4).
 *
 * The statement classified by **literal code prefixes** — `111`, `121`, `122`, `12`, `22`, `222` —
 * so it only worked on the chart this project happens to ship. The failure mode is the dangerous
 * one: a different Egyptian chart numbered 1–5 by nature but with different sub-ranges **saves
 * fine** (the save-time guard only checks the leading digit) and then silently misclassifies every
 * cash flow. Nothing errors, the statement still balances, and the figures are wrong.
 *
 * That matters now rather than hypothetically — the operator's real chart is still pending, and
 * `docs/accounting/` records the one supplied so far as a dummy template.
 *
 * So the account itself says where it belongs. `ledger_accounts.cash_flow_section` is the truth and
 * the report reads it; prefixes survive only in {@see forShippedChart()}, which is a statement about
 * OUR chart used to backfill it, not a rule about charts in general.
 */
final class CashFlowSection
{
    /** The cash and bank accounts — the balance the statement EXPLAINS, not one of its sections. */
    public const CASH = 'cash';

    /** Working capital and non-cash add-backs: receivables, payables, provisions, depreciation. */
    public const OPERATING = 'operating';

    /** Gross non-current assets that move on a real purchase or disposal. */
    public const INVESTING = 'investing';

    /** Owner funding and borrowing — equity and long-term loans. */
    public const FINANCING = 'financing';

    /** @var list<string> */
    public const SECTIONS = [self::CASH, self::OPERATING, self::INVESTING, self::FINANCING];

    /**
     * The section for an account, given what it says and what kind of account it is.
     *
     * **Revenue and expense are deliberately absent.** They net into `net_income` by TYPE, which is
     * already chart-agnostic and needs no column — classifying them would let an operator move
     * revenue into investing and break the statement's own arithmetic.
     *
     * The floor for an unclassified account is OPERATING, not investing: an account somebody adds
     * without saying where it belongs is far more often working capital than a capital asset, and
     * being wrong toward operating leaves the net change in cash correct while being wrong toward
     * investing misstates two subtotals.
     */
    public static function for(?string $stored, string $type): string
    {
        if ($stored !== null && in_array($stored, self::SECTIONS, true)) {
            return $stored;
        }

        return $type === 'equity' ? self::FINANCING : self::OPERATING;
    }

    /**
     * What OUR shipped chart's codes mean — used to backfill the column, and by the seeder.
     *
     * This is the one place the old prefixes survive, and deliberately: they are correct **about
     * this chart**, so encoding today's answer explicitly is what lets the report stop depending on
     * them. A different chart gets classified by an accountant on the screen instead.
     *
     * Order matters and is the same order the report used: `222` before `22`, `122` before `12`.
     */
    public static function forShippedChart(string $code, string $type): ?string
    {
        // Netted by type into net income; no section, and no column value to mis-set.
        if (in_array($type, ['revenue', 'expense'], true)) {
            return null;
        }

        if (str_starts_with($code, '111')) {
            return self::CASH;
        }

        // Provisions — end-of-service, staff leave — are non-cash accruals and an OPERATING
        // add-back, not funding. Must precede the `22` branch, exactly as it did in the report.
        if (str_starts_with($code, '222')) {
            return self::OPERATING;
        }

        if ($type === 'equity' || str_starts_with($code, '22')) {
            return self::FINANCING;
        }

        // Accumulated depreciation is the non-cash counterpart of the depreciation charge — an
        // operating add-back. Investing is only the GROSS non-current assets that move on cash.
        if (str_starts_with($code, '122')) {
            return self::OPERATING;
        }

        if (str_starts_with($code, '12')) {
            return self::INVESTING;
        }

        return self::OPERATING;
    }
}
