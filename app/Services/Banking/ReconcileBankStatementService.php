<?php

namespace App\Services\Banking;

use App\Models\BankAccount;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\JournalLine;
use Illuminate\Support\Facades\DB;

/**
 * The reconciliation itself — slice 5. The arithmetic an auditor asks for.
 *
 * **The statement (Σ) — every reconciliation is this one identity:**
 *
 *     ledger balance = statement closing + Σ(unmatched book postings) − Σ(unmatched statement lines)
 *
 * Read it as the two ways the bank and the books can disagree, and nothing else:
 *
 *  - an **unmatched BOOK posting** is money the books know about that the bank has not shown yet —
 *    a cheque written and not presented, a deposit in transit. The books show LESS cash than the
 *    bank does for an outstanding cheque, which is why it is added to the bank's side;
 *  - an **unmatched STATEMENT line** is money the bank has moved that the books have never heard of
 *    — a charge, an interest credit, a direct debit. Subtracted for the mirror reason.
 *
 * When the identity holds, every difference between the two is accounted for by something named.
 * When it does not, the remainder is the thing nobody has explained, and that number is the whole
 * point of the exercise.
 *
 * **Outstanding items are not limited to this period.** A cheque written in February and still
 * unpresented in March is outstanding in March too, so the book side counts every unmatched posting
 * dated on or before the period end — not merely those inside the period. Windowing it to the
 * period is the classic way to make a reconciliation balance that should not.
 *
 * Read-only: nothing here posts, matches or writes.
 */
class ReconcileBankStatementService
{
    /**
     * @return array{
     *     mapped: bool,
     *     ledger_balance: float,
     *     statement_closing: float,
     *     unmatched_book_total: float,
     *     unmatched_statement_total: float,
     *     expected_ledger: float,
     *     difference: float,
     *     reconciled: bool,
     *     unmatched_book_count: int,
     *     unmatched_statement_count: int,
     * }
     */
    public function for(BankStatement $statement): array
    {
        $account = $statement->getRelationValue('bankAccount');
        $ledgerAccountId = $account instanceof BankAccount ? $account->ledger_account_id : null;

        if (! $ledgerAccountId) {
            // An account nobody has mapped to the chart cannot be reconciled, and saying so is the
            // honest answer — a zeroed report would read as "reconciled".
            return [
                'mapped' => false,
                'ledger_balance' => 0.0, 'statement_closing' => (float) $statement->closing_balance,
                'unmatched_book_total' => 0.0, 'unmatched_statement_total' => 0.0,
                'expected_ledger' => 0.0, 'difference' => 0.0, 'reconciled' => false,
                'unmatched_book_count' => 0, 'unmatched_statement_count' => 0,
            ];
        }

        $periodEnd = $statement->period_end;

        // The books' own answer: every POSTED line on this account up to the period end. A voided
        // entry is excluded — it was reversed, and its reversal is counted in its own right.
        $ledgerBalance = round((float) JournalLine::query()
            ->where('ledger_account_id', $ledgerAccountId)
            ->whereHas('entry', fn ($q) => $q
                ->where('status', 'posted')
                ->whereDate('entry_date', '<=', $periodEnd))
            ->sum(DB::raw('COALESCE(debit, 0) - COALESCE(credit, 0)')), 2);

        // Money the books know and the bank has not shown. Every unmatched posting up to the period
        // end, not just this period's — an unpresented cheque stays outstanding until it clears.
        $unmatchedBook = JournalLine::query()
            ->where('ledger_account_id', $ledgerAccountId)
            ->whereHas('entry', fn ($q) => $q
                ->where('status', 'posted')
                ->whereDate('entry_date', '<=', $periodEnd))
            ->whereDoesntHave('bankMatch');

        $unmatchedBookTotal = round((float) (clone $unmatchedBook)
            ->sum(DB::raw('COALESCE(debit, 0) - COALESCE(credit, 0)')), 2);

        // Money the bank has moved that the books have never heard of.
        $unmatchedLines = BankStatementLine::query()
            ->where('bank_statement_id', $statement->getKey())
            ->whereDoesntHave('matches');

        $unmatchedStatementTotal = round((float) (clone $unmatchedLines)->sum('amount'), 2);

        $statementClosing = round((float) $statement->closing_balance, 2);
        $expectedLedger = round($statementClosing + $unmatchedBookTotal - $unmatchedStatementTotal, 2);
        $difference = round($ledgerBalance - $expectedLedger, 2);

        return [
            'mapped' => true,
            'ledger_balance' => $ledgerBalance,
            'statement_closing' => $statementClosing,
            'unmatched_book_total' => $unmatchedBookTotal,
            'unmatched_statement_total' => $unmatchedStatementTotal,
            'expected_ledger' => $expectedLedger,
            'difference' => $difference,
            // The whole exercise in one boolean — and deliberately NOT "every line is matched".
            // A reconciliation is finished when every difference is EXPLAINED, which is what the
            // identity tests; outstanding items are explanations, not failures.
            'reconciled' => abs($difference) < 0.005,
            'unmatched_book_count' => (clone $unmatchedBook)->count(),
            'unmatched_statement_count' => (clone $unmatchedLines)->count(),
        ];
    }
}
