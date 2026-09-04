<?php

namespace App\Support;

/**
 * **The assertion a financial statement rests on, in ONE wording.**
 *
 * A trial balance whose columns do not foot, a balance sheet whose two sides disagree and a
 * cash-flow statement that does not tie to the movement in the cash accounts are WRONG rather than
 * surprising, so all three screens lead their subheading with the answer and both PDFs print it.
 *
 * The exports carried none of it. Measured 2026-09-04 against `mall_management_qa`: the exported
 * balance sheet's last row was `,,Net income,…` — three section subtotals and a net line, nothing
 * to foot against `Total assets`, and no ✓/✗ — so a file that does not balance is indistinguishable
 * from one that does. That file is the copy that leaves the building for an owner, an accountant or
 * an auditor: exactly the reader who cannot open the ledger to settle it.
 *
 * It is a class rather than a sixth copy of the ternary. The balanced sentence was already written
 * out four times (both page subheadings, both PDF templates) and three exports would have made
 * seven. Same shape and same reason as {@see UnallocatedNotice} — the other sentence three
 * renderers of one statement have to agree about, which drifted once and sent an income statement
 * out of the building quoting a different figure from the screen it was printed from.
 *
 * Two methods rather than one taking a pair of keys, because the two sentences are shaped
 * differently: `balanced`/`not_balanced` are LABELS their renderers mark with ✓/✗, while the
 * cash-flow strings are whole sentences carrying their own mark (`'✓ Reconciles to the actual cash
 * movement.'`). Folding them together prints `✓ ✓ Reconciles…`.
 */
final class StatementIntegrity
{
    /** Debits ≡ credits; Assets ≡ Liabilities + Equity + net income. */
    public static function balance(bool $balanced): string
    {
        return ($balanced ? '✓ ' : '✗ ').__($balanced ? 'admin.reports.balanced' : 'admin.reports.not_balanced');
    }

    /** The statement ties to the actual movement in the cash accounts. */
    public static function cashFlow(bool $reconciled): string
    {
        return __($reconciled ? 'admin.reports.cash_flow_reconciled' : 'admin.reports.cash_flow_unreconciled');
    }
}
