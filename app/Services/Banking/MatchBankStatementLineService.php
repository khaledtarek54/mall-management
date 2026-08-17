<?php

namespace App\Services\Banking;

use App\Models\BankAccount;
use App\Models\BankMatch;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\JournalLine;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Link a bank statement line to the book posting that explains it — slice 3, where the control lands.
 *
 * **A match posts nothing.** It annotates two rows that already exist; creating or removing one
 * leaves every account exactly where it was. That is what keeps this screen from becoming a back
 * door into the GL — a bank charge with no book entry is recorded as an expense through the normal
 * path, with its posting-date and approval guards, and only then matched.
 *
 * **The book side comes from the LEDGER, not from a list of models.** Candidates are journal lines
 * on the bank account's own chart account, so every money source is included the day it ships. A
 * hand-maintained list of the ten models that touch cash would go stale silently: the eleventh
 * source's payments would simply never appear as candidates and would sit unmatched for ever.
 */
class MatchBankStatementLineService
{
    /**
     * Candidate book postings for a statement line: journal lines on this bank account's chart
     * account, unmatched, on the same side of the ledger, near the line's date.
     *
     * Sign, spelled out because getting it backwards is silent: a bank account is an ASSET, so a
     * DEBIT is money in and a CREDIT is money out. A statement line's `amount` is positive for money
     * in. Offering the wrong side would let an operator explain a receipt with a payment.
     *
     * @return Collection<int, JournalLine>
     */
    public function candidatesFor(BankStatementLine $line, int $dayTolerance = 7): Collection
    {
        $statement = $line->statement;
        $account = $statement instanceof BankStatement ? $statement->getRelationValue('bankAccount') : null;

        // No chart account, no candidates — and that is the honest answer rather than an empty list
        // dressed up as "nothing to match". An account nobody has mapped cannot be reconciled.
        if (! $account instanceof BankAccount || ! $account->ledger_account_id) {
            return JournalLine::query()->whereRaw('1 = 0')->get();
        }

        $amount = round((float) $line->amount, 2);
        $moneyIn = $amount > 0;

        return JournalLine::query()
            ->where('ledger_account_id', $account->ledger_account_id)
            ->when($moneyIn, fn ($q) => $q->where('debit', '>', 0))
            ->when(! $moneyIn, fn ($q) => $q->where('credit', '>', 0))
            // `posted` only, and DELIBERATELY not JournalEntry::REPORTABLE_STATUSES.
            //
            // That constant governs SUMMING money, where a void original and its reversal must
            // both be counted so they net to zero. This is a SELECTION: the operator is choosing
            // one book line to explain one bank line, and a voided line explains nothing — it has
            // been reversed, and the reversal (or the re-posted entry) is a live line they can
            // pick instead.
            //
            // Known residual, recorded rather than silently accepted: a void-and-repost therefore
            // offers BOTH the reversal and the new entry as candidates while hiding the original,
            // which is noise on the picker. Narrowing that means excluding reversals whose
            // original is also unmatched, which is a matching-heuristic change and not part of
            // the sum-vs-select rule this file is being corrected for.
            ->whereHas('entry', fn ($q) => $q->where('status', 'posted'))
            ->whereDoesntHave('bankMatch')
            ->whereHas('entry', fn ($q) => $q
                ->whereDate('entry_date', '>=', $line->value_date->copy()->subDays($dayTolerance))
                ->whereDate('entry_date', '<=', $line->value_date->copy()->addDays($dayTolerance)))
            ->with('entry.source')
            ->orderByRaw('ABS(COALESCE(debit, 0) + COALESCE(credit, 0) - ?)', [abs($amount)])
            ->limit(50)
            ->get();
    }

    /** Link one statement line to one journal line. Idempotent per journal line by the unique index. */
    public function match(BankStatementLine $line, JournalLine $journalLine, ?string $notes = null): BankMatch
    {
        return DB::transaction(function () use ($line, $journalLine, $notes) {
            // Lock the book side: two operators matching the same posting to different statement
            // lines must not both succeed. The unique index is the backstop; this is what makes the
            // second one wait and then see the truth rather than hit a constraint violation.
            $journalLine = JournalLine::query()->lockForUpdate()->find($journalLine->getKey());

            if (! $journalLine) {
                throw new DomainException(__('admin.errors.bank_match_missing_posting'));
            }

            if (BankMatch::where('journal_line_id', $journalLine->getKey())->exists()) {
                throw new DomainException(__('admin.errors.bank_match_already_matched'));
            }

            $statement = $line->statement;
            $account = $statement instanceof BankStatement ? $statement->getRelationValue('bankAccount') : null;

            if (! $account instanceof BankAccount
                || (int) $journalLine->ledger_account_id !== (int) $account->ledger_account_id) {
                // Matching across accounts would reconcile one bank with another bank's money and
                // still balance — the failure the whole module exists to prevent.
                throw new DomainException(__('admin.errors.bank_match_wrong_account'));
            }

            $bookAmount = round((float) $journalLine->debit - (float) $journalLine->credit, 2);
            $lineAmount = round((float) $line->amount, 2);

            if (($bookAmount > 0) !== ($lineAmount > 0)) {
                throw new DomainException(__('admin.errors.bank_match_wrong_direction'));
            }

            return BankMatch::create([
                'bank_statement_line_id' => $line->getKey(),
                'journal_line_id' => $journalLine->getKey(),
                'matched_by_user_id' => Auth::id(),
                'matched_at' => now(),
                'notes' => $notes,
            ]);
        });
    }

    /**
     * Undo a match. Reversible on purpose: an operator who links the wrong posting must be able to
     * say so, and since a match never posted anything there is nothing to reverse in the ledger.
     */
    public function unmatch(BankMatch $match): void
    {
        $match->delete();
    }

    /**
     * How much of this statement line the books have explained, and whether that is all of it.
     *
     * A line can carry several matches — a bank shows one line for two cheques banked together — so
     * "matched" is an arithmetic question, not a boolean on the row.
     *
     * @return array{matched: float, outstanding: float, fully: bool}
     */
    public function coverage(BankStatementLine $line): array
    {
        $matched = 0.0;

        foreach ($line->matches()->with('journalLine')->get() as $match) {
            $journalLine = $match->getRelationValue('journalLine');
            if ($journalLine instanceof JournalLine) {
                $matched += round((float) $journalLine->debit - (float) $journalLine->credit, 2);
            }
        }

        $matched = round($matched, 2);
        $outstanding = round((float) $line->amount - $matched, 2);

        return [
            'matched' => $matched,
            'outstanding' => $outstanding,
            'fully' => abs($outstanding) < 0.005,
        ];
    }
}
